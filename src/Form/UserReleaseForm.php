<?php

namespace Drupal\storage_manager\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\storage_manager\Service\NotificationManager;
use Drupal\storage_manager\Service\ViolationManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Confirmation form for members releasing their own storage assignment.
 */
class UserReleaseForm extends ContentEntityForm {

  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info = NULL,
    TimeInterface $time = NULL,
    private readonly EntityTypeManagerInterface $storageEntityTypeManager,
    private readonly NotificationManager $notificationManager,
    private readonly ViolationManager $violationManager,
    private readonly ConfigFactoryInterface $storageConfigFactory,
    private readonly AccountProxyInterface $storageCurrentUser
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('entity_type.manager'),
      $container->get('storage_manager.notification_manager'),
      $container->get('storage_manager.violation_manager'),
      $container->get('config.factory'),
      $container->get('current_user'),
    );
  }

  public function buildForm(array $form, FormStateInterface $form_state, $storage_assignment = NULL): array {
    if (!$this->entity) {
      throw new \InvalidArgumentException('Storage assignment is required.');
    }

    if ((int) $this->entity->get('field_storage_user')->target_id !== (int) $this->storageCurrentUser->id()) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('You are not allowed to release this storage assignment.');
    }

    if ($this->entity->get('field_storage_assignment_status')->value !== 'active') {
      $form['message'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'text' => ['#markup' => $this->t('This storage assignment is not active.')],
      ];
      $form['actions'] = [
        '#type' => 'actions',
        'cancel' => [
          '#type' => 'link',
          '#title' => $this->t('Back to My Storage'),
          '#url' => Url::fromRoute('storage_manager.member_dashboard'),
          '#attributes' => ['class' => ['button']],
        ],
      ];
      return $form;
    }

    $form = parent::buildForm($form, $form_state);

    $unit = $this->entity->get('field_storage_unit')->entity;

    $details = [
      $this->t('Unit: @unit', ['@unit' => $unit?->get('field_storage_unit_id')->value ?: $unit?->id() ?: $this->t('Unknown')]),
      $this->t('Area: @area', ['@area' => $unit?->get('field_storage_area')->entity?->label() ?? $this->t('Unknown')]),
      $this->t('Type: @type', ['@type' => $unit?->get('field_storage_type')->entity?->label() ?? $this->t('Unknown')]),
      $this->t('Start date: @date', ['@date' => $this->entity->get('field_storage_start_date')->value ?: $this->t('Not recorded')]),
    ];

    $form['summary'] = [
      '#theme' => 'item_list',
      '#items' => $details,
      '#weight' => -10,
    ];

    $message = $this->storageConfigFactory->get('storage_manager.settings')->get('release_confirmation_message');
    if ($message) {
      $form['confirmation_message'] = [
        '#type' => 'markup',
        '#markup' => '<p><strong>' . $this->t('Please confirm:') . '</strong> ' . Html::escape($message) . '</p>',
        '#weight' => -5,
      ];
    }

    $form['confirm_statement'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I agree to the statement above.'),
      '#required' => TRUE,
      '#weight' => 0,
    ];

    $photo_verification_mode = $this->storageConfigFactory->get('storage_manager.settings')->get('release_photo_verification');
    if ($photo_verification_mode === 'disabled') {
      $form['field_release_photo']['#access'] = FALSE;
    }
    else {
      $form['field_release_photo']['#required'] = ($photo_verification_mode === 'required');
    }

    foreach ($this->entity->getFieldDefinitions() as $field_name => $definition) {
      if (isset($form[$field_name]) && $field_name !== 'field_release_photo') {
        $form[$field_name]['#access'] = FALSE;
      }
    }

    $form['actions']['submit']['#value'] = $this->t('Release storage');

    return $form;
  }

  public function save(array $form, FormStateInterface $form_state) {
    $assignment = $this->getEntity();

    if ((int) $assignment->get('field_storage_user')->target_id !== (int) $this->storageCurrentUser->id()) {
      $this->messenger()->addError($this->t('You are not allowed to release this storage assignment.'));
      $form_state->setRedirect('storage_manager.member_dashboard');
      return;
    }

    if ($assignment->get('field_storage_assignment_status')->value !== 'active') {
      $this->messenger()->addError($this->t('This storage assignment is not active.'));
      $form_state->setRedirect('storage_manager.member_dashboard');
      return;
    }

    $release_date = (new DrupalDateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
    $assignment->set('field_storage_assignment_status', 'ended');
    $assignment->set('field_storage_end_date', $release_date);

    $status = parent::save($form, $form_state);

    $violation_total = NULL;
    $active_violation = $this->violationManager->loadActiveViolation((int) $assignment->id());
    if ($active_violation) {
      $resolved = new DrupalDateTime('now', new \DateTimeZone('UTC'));
      $violation_total = $this->violationManager->finalizeViolation($active_violation, $resolved->getPhpDateTime());
    }

    $unit = $assignment->get('field_storage_unit')->entity;
    if ($unit) {
      $unit->set('field_storage_status', 'vacant');
      $unit->save();
    }

    $user_entity = $this->storageEntityTypeManager->getStorage('user')->load($this->storageCurrentUser->id());
    $this->notificationManager->sendEvent('release', [
      'assignment' => $assignment,
      'unit' => $unit,
      'user' => $user_entity,
      'release_date' => $release_date,
    ]);

    if ($violation_total !== NULL) {
      $context = [
        'assignment' => $assignment,
        'violation' => $active_violation,
        'unit' => $unit,
        'user' => $user_entity,
        'release_date' => $release_date,
        'violation_total' => $violation_total,
        'violation_resolved' => $active_violation?->get('field_storage_vi_resolved')->value,
      ];
      $this->notificationManager->sendEvent('violation_resolved', $context);
      if ($violation_total > 0) {
        $this->notificationManager->sendEvent('violation_fine', $context);
      }
    }

    $this->messenger()->addStatus($this->t('Your storage has been released.'));
    $form_state->setRedirect('storage_manager.member_dashboard');

    return $status;
  }
}
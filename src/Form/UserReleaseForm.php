<?php

namespace Drupal\storage_manager\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
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
use Drupal\storage_manager\Service\StripeAssignmentManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Element;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;

/**
 * Confirmation form for members releasing their own storage assignment.
 */
class UserReleaseForm extends ContentEntityForm {
  use DependencySerializationTrait;

  private StripeAssignmentManager $stripeAssignmentManager;
  private EntityTypeManagerInterface $storageEntityTypeManager;
  private NotificationManager $notificationManager;
  private ViolationManager $violationManager;
  private ConfigFactoryInterface $storageConfigFactory;
  private AccountProxyInterface $storageCurrentUser;
  private CacheTagsInvalidatorInterface $cacheTagsInvalidator;

  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    EntityTypeManagerInterface $storageEntityTypeManager,
    NotificationManager $notificationManager,
    ViolationManager $violationManager,
    ConfigFactoryInterface $storageConfigFactory,
    AccountProxyInterface $storageCurrentUser,
    CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    ?StripeAssignmentManager $stripeAssignmentManager = NULL
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->storageEntityTypeManager = $storageEntityTypeManager;
    $this->notificationManager = $notificationManager;
    $this->violationManager = $violationManager;
    $this->storageConfigFactory = $storageConfigFactory;
    $this->storageCurrentUser = $storageCurrentUser;
    $this->cacheTagsInvalidator = $cacheTagsInvalidator;
    if (!$stripeAssignmentManager) {
      throw new \InvalidArgumentException('StripeAssignmentManager service is required.');
    }
    $this->stripeAssignmentManager = $stripeAssignmentManager;
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
      $container->get('cache_tags.invalidator'),
      $container->get('storage_manager.stripe_assignment_manager'),
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

    // Hide all fields by default, we will only show the release photo if needed.
    foreach ($this->entity->getFieldDefinitions() as $field_name => $definition) {
      if (isset($form[$field_name])) {
        $form[$field_name]['#access'] = FALSE;
      }
    }

    $photo_verification_mode = $this->storageConfigFactory->get('storage_manager.settings')->get('release_photo_verification');
    if ($photo_verification_mode !== 'disabled' && isset($form['field_release_photo'])) {
      $form['field_release_photo']['#access'] = TRUE;
      $form['field_release_photo']['#required'] = ($photo_verification_mode === 'required');
      $form['field_release_photo']['#after_build'][] = [static::class, 'cleanReleasePhotoWidget'];
    }

    $form['actions']['submit']['#value'] = $this->t('Release storage');

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    if ($this->isReleasePhotoRequired() && !$this->hasReleasePhotoUpload($form_state->getValue('field_release_photo'))) {
      $form_state->setErrorByName('field_release_photo', $this->t('Please upload a photo showing the cleared storage space.'));
    }
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

    if ($assignment->hasField('field_release_photo')) {
      $default_alt = (string) $this->t('Storage release photo');
      foreach ($assignment->get('field_release_photo') as $item) {
        if ($item->isEmpty()) {
          continue;
        }
        if ($item->alt === NULL || $item->alt === '') {
          $item->alt = $default_alt;
        }
      }
    }

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

    if ($this->stripeAssignmentManager->isEnabled()) {
      try {
        $this->stripeAssignmentManager->releaseAssignment($assignment);
      }
      catch (\Throwable $e) {
        $this->logger('storage_manager')->error('Failed to release Stripe billing for storage assignment @id: @message', [
          '@id' => $assignment->id(),
          '@message' => $e->getMessage(),
        ]);
        $this->messenger()->addWarning($this->t('We could not automatically stop Stripe billing for this storage. Staff will review the subscription.'));
      }
    }

    $this->messenger()->addStatus($this->t('Your storage has been released.'));
    $this->cacheTagsInvalidator->invalidateTags(['storage_assignment_list']);
    $form_state->setRedirect('storage_manager.member_dashboard');

    return $status;
  }

  private function isReleasePhotoRequired(): bool {
    return $this->storageConfigFactory->get('storage_manager.settings')->get('release_photo_verification') === 'required';
  }

  private function hasReleasePhotoUpload($value): bool {
    if (!is_array($value)) {
      return FALSE;
    }

    foreach ($value as $item) {
      if (!is_array($item)) {
        continue;
      }
      $target_id = $item['target_id'] ?? NULL;
      $fids = isset($item['fids']) ? array_filter((array) $item['fids']) : [];
      if (!empty($target_id) || !empty($fids)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  public static function cleanReleasePhotoWidget(array $element, FormStateInterface $form_state): array {
    if (!isset($element['widget']) || !is_array($element['widget'])) {
      return $element;
    }

    foreach (Element::children($element['widget']) as $delta) {
      if (isset($element['widget'][$delta]['alt'])) {
        $element['widget'][$delta]['alt']['#required'] = FALSE;
        $element['widget'][$delta]['alt']['#access'] = FALSE;
        $element['widget'][$delta]['alt']['#attributes']['required'] = FALSE;
        unset($element['widget'][$delta]['alt']['#states']['required']);
        unset($element['widget'][$delta]['alt']['#attributes']['aria-required']);
        if (isset($element['widget'][$delta]['alt']['#attributes']['class'])) {
          $classes = (array) $element['widget'][$delta]['alt']['#attributes']['class'];
          $element['widget'][$delta]['alt']['#attributes']['class'] = array_values(array_diff($classes, ['required']));
        }
      }
      if (isset($element['widget'][$delta]['title'])) {
        $element['widget'][$delta]['title']['#required'] = FALSE;
        $element['widget'][$delta]['title']['#access'] = FALSE;
        unset($element['widget'][$delta]['title']['#states']['required']);
      }
    }

    return $element;
  }
}

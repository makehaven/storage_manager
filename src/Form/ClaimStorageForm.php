<?php

namespace Drupal\storage_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\storage_manager\Service\AssignmentGuard;
use Drupal\storage_manager\Service\NotificationManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form that allows members to claim vacant storage units.
 */
class ClaimStorageForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $storageEntityTypeManager,
    private readonly AssignmentGuard $guard,
    private readonly ConfigFactoryInterface $storageConfigFactory,
    private readonly NotificationManager $notificationManager,
    private readonly AccountProxyInterface $storageCurrentUser,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('storage_manager.assignment_guard'),
      $container->get('config.factory'),
      $container->get('storage_manager.notification_manager'),
      $container->get('current_user'),
      $container->get('cache_tags.invalidator'),
    );
  }

  public function getFormId(): string {
    return 'storage_manager_claim_storage_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $units = $this->loadClaimableUnits();

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Choose an available storage unit to claim. The unit will be assigned to your account immediately.') . '</p>',
    ];

    if (!$units) {
      $form['no_units'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'text' => ['#markup' => $this->t('No storage units are currently available to claim. Please check back later or contact staff for assistance.')],
      ];
      return $form;
    }

    $options = [];
    foreach ($units as $unit) {
      $unit_label = $unit->get('field_storage_unit_id')->value ?: $unit->id();
      $area = $unit->get('field_storage_area')->entity?->label();
      $type = $unit->get('field_storage_type')->entity?->label();
      $price_value = $unit->get('field_storage_type')->entity?->get('field_monthly_price')->value;
      $price = $price_value !== NULL && $price_value !== ''
        ? '$' . number_format((float) $price_value, 2)
        : $this->t('No monthly cost set');

      $parts = [$this->t('Unit @unit', ['@unit' => $unit_label])];
      if ($area) {
        $parts[] = $area;
      }
      if ($type) {
        $parts[] = $type;
      }
      $parts[] = $price;
      $options[$unit->id()] = implode(' · ', array_map(fn($item) => (string) $item, $parts));
    }

    $form['storage_unit'] = [
      '#type' => 'radios',
      '#title' => $this->t('Available storage units'),
      '#options' => $options,
      '#required' => TRUE,
    ];

    $agreement = $this->storageConfigFactory->get('storage_manager.settings')->get('claim_agreement');
    if (!empty($agreement['value'])) {
      $form['agreement'] = [
        '#type' => 'processed_text',
        '#text' => $agreement['value'],
        '#format' => $agreement['format'] ?? 'basic_html',
        '#weight' => 20,
      ];
    }

    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I agree to the storage terms above.'),
      '#required' => TRUE,
      '#weight' => 25,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Claim storage'),
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $unit_id = (int) $form_state->getValue('storage_unit');
    if (!$unit_id) {
      $form_state->setErrorByName('storage_unit', $this->t('Select a storage unit to claim.'));
      return;
    }

    $unit = $this->storageEntityTypeManager->getStorage('storage_unit')->load($unit_id);
    if (!$unit) {
      $form_state->setErrorByName('storage_unit', $this->t('The selected storage unit could not be found.'));
      return;
    }

    $status = $unit->get('field_storage_status')->value;
    if ($status !== 'vacant') {
      $form_state->setErrorByName('storage_unit', $this->t('That storage unit has already been claimed.'));
      return;
    }

    if ($this->guard->unitHasActiveAssignment($unit_id)) {
      $form_state->setErrorByName('storage_unit', $this->t('That storage unit already has an active assignment.'));
      return;
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $unit_id = (int) $form_state->getValue('storage_unit');
    $unit_storage = $this->storageEntityTypeManager->getStorage('storage_unit');
    $assignment_storage = $this->storageEntityTypeManager->getStorage('storage_assignment');

    /** @var \Drupal\eck\EckEntityInterface|null $unit */
    $unit = $unit_storage->load($unit_id);
    if (!$unit) {
      $this->messenger()->addError($this->t('Unable to load the selected storage unit.'));
      return;
    }

    if ($unit->get('field_storage_status')->value !== 'vacant' || $this->guard->unitHasActiveAssignment($unit_id)) {
      $this->messenger()->addError($this->t('That storage unit is no longer available.'));
      return;
    }

    $type_term = $unit->get('field_storage_type')->entity;
    $price_snapshot = $type_term?->get('field_monthly_price')->value;

    $assignment = $assignment_storage->create([
      'type' => 'storage_assignment',
      'field_storage_unit' => $unit->id(),
      'field_storage_user' => $this->storageCurrentUser->id(),
      'field_storage_start_date' => date('Y-m-d'),
      'field_storage_assignment_status' => 'active',
      'field_storage_price_snapshot' => $price_snapshot,
      'field_storage_complimentary' => 0,
    ]);
    $assignment->save();

    $unit->set('field_storage_status', 'occupied');
    $unit->save();

    $user = $this->storageEntityTypeManager->getStorage('user')->load($this->storageCurrentUser->id());
    $this->notificationManager->sendEvent('assignment', [
      'assignment' => $assignment,
      'unit' => $unit,
      'user' => $user,
    ]);

    $this->messenger()->addStatus($this->t('You have claimed storage unit @unit.', ['@unit' => $unit->get('field_storage_unit_id')->value ?: $unit->id()]));
    $this->cacheTagsInvalidator->invalidateTags(['storage_assignment_list']);
    $form_state->setRedirect('storage_manager.member_dashboard');
  }

  /**
   * Returns claimable storage units keyed by entity ID.
   */
  protected function loadClaimableUnits(): array {
    $storage = $this->storageEntityTypeManager->getStorage('storage_unit');
    $query = $storage->getQuery()
      ->condition('field_storage_status', 'vacant')
      ->sort('field_storage_unit_id', 'ASC')
      ->accessCheck(TRUE);
    $ids = $query->execute();
    if (!$ids) {
      return [];
    }

    $units = $storage->loadMultiple($ids);

    // Filter out any that may have an active assignment as a fallback check.
    return array_filter($units, fn($unit) => !$this->guard->unitHasActiveAssignment($unit->id()));
  }
}

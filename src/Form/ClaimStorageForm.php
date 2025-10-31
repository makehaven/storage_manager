<?php

namespace Drupal\storage_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\storage_manager\Service\AssignmentGuard;
use Drupal\storage_manager\Service\NotificationManager;
use Drupal\storage_manager\Service\StripeAssignmentManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form that allows members to claim vacant storage units.
 */
class ClaimStorageForm extends FormBase {

  protected EntityTypeManagerInterface $storageEntityTypeManager;
  protected AssignmentGuard $guard;
  protected ConfigFactoryInterface $storageConfigFactory;
  protected NotificationManager $notificationManager;
  protected StripeAssignmentManager $stripeAssignmentManager;
  protected AccountProxyInterface $storageCurrentUser;
  protected CacheTagsInvalidatorInterface $cacheTagsInvalidator;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, AssignmentGuard $guard, ConfigFactoryInterface $config_factory, NotificationManager $notification_manager, StripeAssignmentManager $stripe_assignment_manager, AccountProxyInterface $current_user, CacheTagsInvalidatorInterface $cache_tags_invalidator) {
    $this->storageEntityTypeManager = $entity_type_manager;
    $this->guard = $guard;
    $this->storageConfigFactory = $config_factory;
    $this->notificationManager = $notification_manager;
    $this->stripeAssignmentManager = $stripe_assignment_manager;
    $this->storageCurrentUser = $current_user;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
  }

  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('storage_manager.assignment_guard'),
      $container->get('config.factory'),
      $container->get('storage_manager.notification_manager'),
      $container->get('storage_manager.stripe_assignment_manager'),
      $container->get('current_user'),
      $container->get('cache_tags.invalidator')
    );
  }

  public function getFormId(): string {
    return 'storage_manager_claim_storage_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $units = $this->loadClaimableUnits();
    $config = $this->storageConfigFactory->get('storage_manager.settings');
    $stripe_enabled = (bool) $config->get('stripe.enable_billing');

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Choose an available storage unit to claim. The unit will be assigned to your account immediately.') . '</p>',
    ];

    if ($stripe_enabled) {
      $portal_link = Link::fromTextAndUrl(
        $this->t('storage billing portal'),
        Url::fromRoute('storage_manager_billing.portal')
      )->toString();

      $form['stripe_notice'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--info']],
        'text' => [
          '#markup' => $this->t('Stripe billing is enabled for storage. When you claim a unit we automatically create or update your Stripe subscription. You will receive a receipt once charges begin, and you can review invoices any time on the !portal.', ['!portal' => $portal_link]),
        ],
      ];
    }

    $type_options = $this->buildTypeOptions($units);
    $selected_type = $form_state->getValue('filter_type') ?: NULL;

    $form['filter_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['storage-claim-filter']],
      '#weight' => -10,
      '#access' => !empty($type_options),
    ];
    $form['filter_wrapper']['filter_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Filter by storage type'),
      '#options' => $type_options,
      '#empty_option' => $this->t('- All types -'),
      '#default_value' => $selected_type,
      '#ajax' => [
        'callback' => '::refreshAvailableUnits',
        'event' => 'change',
        'wrapper' => 'storage-claim-units',
      ],
    ];
    $form['filter_wrapper']['reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset'),
      '#limit_validation_errors' => [],
      '#submit' => ['::resetFilters'],
      '#ajax' => [
        'callback' => '::refreshAvailableUnits',
        'wrapper' => 'storage-claim-units',
      ],
    ];

    $filtered_units = $this->filterUnitsByType($units, $selected_type);

    $form['units_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'storage-claim-units'],
    ];

    if (!$filtered_units) {
      $form['units_wrapper']['no_units'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'text' => ['#markup' => $this->t('No storage units are currently available to claim. Please check back later or contact staff for assistance.')],
      ];
    }
    else {
      $options = [];
      foreach ($filtered_units as $unit) {
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

      $form['units_wrapper']['storage_unit'] = [
        '#type' => 'radios',
        '#title' => $this->t('Available storage units'),
        '#options' => $options,
        '#required' => TRUE,
      ];
    }

    $agreement = $this->storageConfigFactory->get('storage_manager.settings')->get('claim_agreement');
    if (!empty($agreement['value'])) {
      $form['agreement'] = [
        '#type' => 'processed_text',
        '#text' => $agreement['value'],
        '#format' => $agreement['format'] ?? 'basic_html',
        '#weight' => 20,
        '#access' => !empty($filtered_units),
      ];
    }

    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I agree to the storage terms above.'),
      '#required' => TRUE,
      '#weight' => 25,
      '#access' => !empty($filtered_units),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#access' => !empty($filtered_units),
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

    $stripe_price_id = '';
    if ($this->stripeAssignmentManager->isEnabled()) {
      $stripe_price_id = (string) $this->storageConfigFactory->get('storage_manager.settings')->get('stripe.default_price_id');
    }

    $values = [
      'type' => 'storage_assignment',
      'field_storage_unit' => $unit->id(),
      'field_storage_user' => $this->storageCurrentUser->id(),
      'field_storage_start_date' => date('Y-m-d'),
      'field_storage_assignment_status' => 'active',
      'field_storage_price_snapshot' => $price_snapshot,
      'field_storage_complimentary' => 0,
    ];
    if ($stripe_price_id !== '') {
      $values['field_stripe_price_id'] = $stripe_price_id;
    }

    $assignment = $assignment_storage->create($values);
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

    if ($this->stripeAssignmentManager->isEnabled()) {
      try {
        $this->stripeAssignmentManager->syncAssignment($assignment);
        $status = $assignment->hasField('field_storage_stripe_status')
          ? (string) ($assignment->get('field_storage_stripe_status')->value ?? '')
          : '';
        if ($status !== '') {
          $this->messenger()->addStatus($this->t('Stripe billing status for this storage is now "@status".', ['@status' => ucfirst($status)]));
        }
        else {
          $this->messenger()->addStatus($this->t('Stripe billing for this storage has been queued.'));
        }
      }
      catch (\Throwable $e) {
        $this->logger('storage_manager')->error('Failed to synchronize storage assignment @id with Stripe: @message', [
          '@id' => $assignment->id(),
          '@message' => $e->getMessage(),
        ]);
        $this->messenger()->addWarning($this->t('We could not sync this storage with Stripe automatically: @reason', ['@reason' => $e->getMessage()]));
      }
    }
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

  /**
   * Builds a list of storage types present in the available units.
   */
  protected function buildTypeOptions(array $units): array {
    $options = [];
    foreach ($units as $unit) {
      $type = $unit->get('field_storage_type')->entity;
      if ($type) {
        $options[$type->id()] = $type->label();
      }
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /**
   * Filters units by selected storage type.
   */
  protected function filterUnitsByType(array $units, ?string $type_id): array {
    if (!$type_id) {
      return $units;
    }
    return array_filter($units, static function ($unit) use ($type_id) {
      $type = $unit->get('field_storage_type')->entity;
      return $type && (string) $type->id() === (string) $type_id;
    });
  }

  /**
   * Ajax callback for type filtering.
   */
  public function refreshAvailableUnits(array &$form, FormStateInterface $form_state): array {
    return $form['units_wrapper'] ?? $form;
  }

  /**
   * Reset filter handler.
   */
  public function resetFilters(array &$form, FormStateInterface $form_state): void {
    $form_state->setValue('filter_type', NULL);
    $form_state->setRebuild(TRUE);
  }
}

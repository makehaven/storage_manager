<?php

namespace Drupal\storage_manager\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\storage_manager\Service\AssignmentGuard;
use Drupal\storage_manager\Service\NotificationManager;
use Drupal\storage_manager\Service\StripeAssignmentManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AssignForm extends FormBase {

  public function __construct(
    private AssignmentGuard $guard,
    private NotificationManager $notificationManager,
    protected $configFactory,
    private StripeAssignmentManager $stripeAssignmentManager
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('storage_manager.assignment_guard'),
      $container->get('storage_manager.notification_manager'),
      $container->get('config.factory'),
      $container->get('storage_manager.stripe_assignment_manager'),
    );
  }

  public function getFormId(): string {
    return 'storage_manager_assign_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $storage_unit = NULL): array {
    if (!$storage_unit) {
      $storage_unit = \Drupal::routeMatch()->getParameter('storage_unit');
    }

    if (!$storage_unit) {
      throw new \InvalidArgumentException('Storage unit entity is required.');
    }

    $form['unit_id'] = [
      '#type' => 'value',
      '#value' => $storage_unit->id(),
    ];

    $form['user'] = [
      '#type' => 'entity_autocomplete',
      '#title' => 'Member',
      '#target_type' => 'user',
      '#required' => TRUE,
    ];

    $form['start_date'] = [
      '#type' => 'date',
      '#title' => 'Start date',
      '#default_value' => date('Y-m-d'),
      '#required' => TRUE,
    ];

    $form['complimentary'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Complimentary assignment (no monthly cost)'),
      '#description' => $this->t('Use when providing storage at no charge to staff or volunteers.'),
    ];

    $form['bill_via_stripe'] = [
      '#type' => 'checkbox',
      '#title' => 'Create Stripe subscription (optional)',
      '#description' => $this->t('This feature is not yet implemented.'),
      '#default_value' => 0,
      '#access' => FALSE,
    ];

    if ($this->stripeAssignmentManager->isEnabled()) {
      $form['stripe_help'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--info']],
        'text' => [
          '#markup' => $this->t('Stripe billing is enabled. After this assignment is saved, the member’s Stripe subscription will be created or updated automatically. You can open the subscription from the storage dashboard if follow-up is needed.'),
        ],
      ];
    }

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Assign',
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $unit_id = $form_state->getValue('unit_id');
    if ($this->guard->unitHasActiveAssignment($unit_id)) {
      $form_state->setErrorByName('user', $this->t('This unit already has an active assignment.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $unit_id = $form_state->getValue('unit_id');
    /** @var \Drupal\Core\Entity\EntityStorageInterface $u_storage */
    $u_storage = \Drupal::entityTypeManager()->getStorage('storage_unit');
    $unit = $u_storage->load($unit_id);

    $a_storage = \Drupal::entityTypeManager()->getStorage('storage_assignment');

    // Snapshot price from storage_type term (if present).
    $price_snapshot = NULL;
    if ($term = $unit->get('field_storage_type')->entity) {
      $price_snapshot = $term->get('field_monthly_price')->value ?? NULL;
    }

    $user_id = $form_state->getValue('user');
    $user = \Drupal::entityTypeManager()->getStorage('user')->load($user_id);

    $stripe_price_id = '';
    if ($this->stripeAssignmentManager->isEnabled()) {
      $stripe_price_id = (string) $this->configFactory->get('storage_manager.settings')->get('stripe.default_price_id');
    }

    $values = [
      'type' => 'storage_assignment',
      'field_storage_unit' => $unit->id(),
      'field_storage_user' => $user_id,
      'field_storage_start_date' => $form_state->getValue('start_date'),
      'field_storage_assignment_status' => 'active',
      'field_storage_price_snapshot' => $price_snapshot,
      'field_storage_complimentary' => $form_state->getValue('complimentary') ? 1 : 0,
    ];
    if ($stripe_price_id !== '') {
      $values['field_stripe_price_id'] = $stripe_price_id;
    }

    $assignment = $a_storage->create($values);
    $assignment->save();

    // Update unit status to Occupied.
    $unit->set('field_storage_status', 'occupied');
    $unit->save();

    $this->notificationManager->sendEvent('assignment', [
      'assignment' => $assignment,
      'unit' => $unit,
      'user' => $user,
    ]);

    // (Optional) Stripe create subscription later via SubscriptionManager.

    $this->messenger()->addStatus($this->t('Assigned unit to user.'));
    if ($this->stripeAssignmentManager->isEnabled()) {
      try {
        $this->stripeAssignmentManager->syncAssignment($assignment);
        $status = $assignment->hasField('field_storage_stripe_status')
          ? (string) ($assignment->get('field_storage_stripe_status')->value ?? '')
          : '';
        if ($status !== '') {
          $this->messenger()->addStatus($this->t('Stripe billing status is "@status".', ['@status' => ucfirst($status)]));
        }
        else {
          $this->messenger()->addStatus($this->t('Stripe billing update queued.'));
        }
      }
      catch (\Throwable $e) {
        $this->logger('storage_manager')->error('Failed to synchronize storage assignment @id with Stripe: @message', [
          '@id' => $assignment->id(),
          '@message' => $e->getMessage(),
        ]);
        $this->messenger()->addWarning($this->t('Automatic Stripe sync failed: @reason', ['@reason' => $e->getMessage()]));
      }
    }

    $form_state->setRedirectUrl(Url::fromRoute('storage_manager.dashboard'));
  }
}

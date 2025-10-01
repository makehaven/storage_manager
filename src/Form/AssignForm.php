<?php

namespace Drupal\storage_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\storage_manager\Service\AssignmentGuard;
use Drupal\storage_manager\Service\NotificationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AssignForm extends FormBase {

  public function __construct(private AssignmentGuard $guard, private NotificationManager $notificationManager) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('storage_manager.assignment_guard'),
      $container->get('storage_manager.notification_manager'),
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

    $assignment = $a_storage->create([
      'type' => 'storage_assignment',
      'field_storage_unit' => $unit->id(),
      'field_storage_user' => $form_state->getValue('user'),
      'field_storage_start_date' => $form_state->getValue('start_date'),
      'field_storage_assignment_status' => 'active',
      'field_storage_price_snapshot' => $price_snapshot,
      'field_storage_complimentary' => $form_state->getValue('complimentary') ? 1 : 0,
    ]);
    $assignment->save();

    // Update unit status to Occupied.
    $unit->set('field_storage_status', 'occupied');
    $unit->save();

    $this->notificationManager->sendEvent('assignment', [
      'assignment' => $assignment,
      'unit' => $unit,
    ]);

    // (Optional) Stripe create subscription later via SubscriptionManager.

    $this->messenger()->addStatus($this->t('Assigned unit to user.'));

    $form_state->setRedirectUrl(Url::fromRoute('storage_manager.dashboard'));
  }
}

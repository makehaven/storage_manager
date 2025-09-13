<?php

namespace Drupal\storage_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\storage_manager\Service\AssignmentGuard;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AssignForm extends FormBase {

  public function __construct(private AssignmentGuard $guard) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('storage_manager.assignment_guard')
    );
  }

  public function getFormId(): string {
    return 'storage_manager_assign_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $unit = NULL): array {
    $form['unit_id'] = [
      '#type' => 'value',
      '#value' => $unit->id(),
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

    $form['bill_via_stripe'] = [
      '#type' => 'checkbox',
      '#title' => 'Create Stripe subscription (optional)',
      '#default_value' => 0,
    ];

    $form['issue_open'] = [
      '#type' => 'checkbox',
      '#title' => 'Flag issue on creation',
      '#default_value' => 0,
    ];

    $form['issue_note'] = [
      '#type' => 'textarea',
      '#title' => 'Issue note',
      '#states' => [
        'visible' => [
          ':input[name="issue_open"]' => ['checked' => TRUE],
        ],
      ],
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
      'field_storage_unit' => $unit->id(),
      'field_storage_user' => $form_state->getValue('user'),
      'field_start_date' => $form_state->getValue('start_date'),
      'field_assignment_status' => 'Active',
      'field_price_snapshot' => $price_snapshot,
      'field_issue_open' => $form_state->getValue('issue_open') ? 1 : 0,
      'field_issue_note' => $form_state->getValue('issue_note'),
    ]);
    $assignment->save();

    // Update unit status to Occupied.
    $unit->set('field_storage_status', 'Occupied');
    $unit->save();

    // (Optional) Stripe create subscription later via SubscriptionManager.

    $this->messenger()->addStatus($this->t('Assigned unit to user.'));

    $form_state->setRedirectUrl(Url::fromRoute('storage_manager.dashboard'));
  }
}

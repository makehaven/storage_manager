<?php

namespace Drupal\storage_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class ReleaseForm extends FormBase {

  public function getFormId(): string {
    return 'storage_manager_release_form';
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

    $form['end_date'] = [
      '#type' => 'date',
      '#title' => 'End date',
      '#default_value' => date('Y-m-d'),
      '#required' => TRUE,
    ];

    $form['cancel_stripe'] = [
      '#type' => 'checkbox',
      '#title' => 'Cancel Stripe subscription (if any)',
      '#default_value' => 0,
      '#access' => FALSE,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Release',
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $unit_id = $form_state->getValue('unit_id');

    $a_storage = \Drupal::entityTypeManager()->getStorage('storage_assignment');
    $ids = $a_storage->getQuery()
      ->condition('field_storage_unit', $unit_id)
      ->condition('field_storage_assignment_status', 'active')
      ->accessCheck(FALSE)
      ->execute();

    if ($ids) {
      $assignment = $a_storage->load(reset($ids));
      $assignment->set('field_storage_assignment_status', 'ended');
      $assignment->set('field_storage_end_date', $form_state->getValue('end_date'));
      $assignment->save();

      // (Optional) Stripe cancel if enabled.
    }

    $u_storage = \Drupal::entityTypeManager()->getStorage('storage_unit');
    if ($unit = $u_storage->load($unit_id)) {
      $unit->set('field_storage_status', 'Vacant');
      $unit->save();
    }

    $this->messenger()->addStatus($this->t('Released unit.'));
    $form_state->setRedirectUrl(Url::fromRoute('storage_manager.dashboard'));
  }
}

<?php

namespace Drupal\storage_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\eck\EckEntityInterface;

class EditAssignmentForm extends FormBase {

  public function getFormId() {
    return 'storage_manager_edit_assignment_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, EckEntityInterface $assignment = NULL) {
    $form['assignment'] = [
      '#type' => 'value',
      '#value' => $assignment,
    ];

    $form['field_storage_issue_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Issue Note'),
      '#default_value' => $assignment->get('field_storage_issue_note')->value,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $assignment = $form_state->getValue('assignment');
    $assignment->set('field_storage_issue_note', $form_state->getValue('field_storage_issue_note'));
    $assignment->save();
    $this->messenger()->addStatus($this->t('Assignment has been updated.'));
  }
}

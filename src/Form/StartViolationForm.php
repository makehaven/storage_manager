<?php

namespace Drupal\storage_manager\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\eck\EckEntityInterface;
use Drupal\storage_manager\Service\NotificationManager;
use Drupal\storage_manager\Service\ViolationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for starting a new violation on an assignment.
 */
class StartViolationForm extends FormBase {

  public function __construct(
    private readonly ViolationManager $violationManager,
    private readonly NotificationManager $notificationManager
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('storage_manager.violation_manager'),
      $container->get('storage_manager.notification_manager')
    );
  }

  public function getFormId(): string {
    return 'storage_manager_start_violation_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EckEntityInterface $storage_assignment = NULL): array {
    if (!$storage_assignment) {
      throw new \InvalidArgumentException('A storage assignment is required.');
    }

    $form['assignment'] = [
      '#type' => 'value',
      '#value' => $storage_assignment,
    ];

    $form['start_violation_start'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Violation start'),
      '#default_value' => new DrupalDateTime('now'),
      '#date_time_element' => 'none',
    ];

    $form['start_violation_daily_rate'] = [
      '#type' => 'number',
      '#title' => $this->t('Daily charge'),
      '#default_value' => $this->violationManager->getDefaultDailyRate(),
      '#step' => '0.01',
      '#min' => '0',
    ];

    $form['start_violation_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Violation notes'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start Violation'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\eck\EckEntityInterface $assignment */
    $assignment = $form_state->getValue('assignment');
    $start_input = $form_state->getValue('start_violation_start');
    $daily = $form_state->getValue('start_violation_daily_rate');
    $note = $form_state->getValue('start_violation_note');

    $violation = $this->violationManager->startViolation($assignment, [
      'start' => $start_input instanceof DrupalDateTime ? $start_input : NULL,
      'daily_rate' => $daily,
      'note' => $note,
    ]);

    $context = [
      'assignment' => $assignment,
      'violation' => $violation,
    ];
    $this->notificationManager->sendEvent('violation_warning', $context);
    $this->messenger()->addStatus($this->t('Violation started.'));
    $form_state->setRedirect('storage_manager.dashboard');
  }
}
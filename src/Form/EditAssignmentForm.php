<?php

namespace Drupal\storage_manager\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\eck\EckEntityInterface;
use Drupal\storage_manager\Service\NotificationManager;
use Drupal\storage_manager\Service\ViolationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

class EditAssignmentForm extends FormBase {

  public function __construct(
    private readonly ViolationManager $violationManager,
    private readonly NotificationManager $notificationManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('storage_manager.violation_manager'),
      $container->get('storage_manager.notification_manager'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'storage_manager_edit_assignment_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EckEntityInterface $assignment = NULL): array {
    if (!$assignment) {
      $assignment = \Drupal::routeMatch()->getParameter('storage_assignment');
    }
    if (!$assignment instanceof EckEntityInterface) {
      throw new \InvalidArgumentException('A storage assignment is required.');
    }

    $form['assignment'] = [
      '#type' => 'value',
      '#value' => $assignment,
    ];

    $violations = $this->violationManager->loadViolations((int) $assignment->id());
    $active_violation = $this->violationManager->loadActiveViolation((int) $assignment->id());
    if ($active_violation) {
      $form['active_violation'] = $this->buildActiveViolationSection($active_violation);
    }
    else {
      $form['start_violation'] = $this->buildStartViolationSection();
    }

    $form['violation_history'] = $this->buildViolationHistory($violations);

    $form['issue_note'] = [
      '#type' => 'details',
      '#title' => $this->t('Notes'),
      '#open' => TRUE,
    ];
    $form['issue_note']['assignment_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Assignment note'),
      '#default_value' => $assignment->get('field_storage_issue_note')->value,
      '#description' => $this->t('Optional context or notes about this assignment.'),
    ];
    if ($assignment->hasField('field_stripe_manual_review')) {
      $manual_required = (bool) ($assignment->get('field_stripe_manual_review')->value ?? FALSE);
      $manual_note = $assignment->hasField('field_stripe_manual_note')
        ? (string) ($assignment->get('field_stripe_manual_note')->value ?? '')
        : '';

      $form['stripe_manual'] = [
        '#type' => 'details',
        '#title' => $this->t('Stripe manual follow-up'),
        '#open' => $manual_required,
      ];
      $form['stripe_manual']['manual_status'] = [
        '#type' => 'item',
        '#title' => $this->t('Status'),
        '#markup' => $manual_required
          ? $this->t('Manual review required')
          : $this->t('No manual review required'),
      ];
      if ($manual_note !== '') {
        $form['stripe_manual']['manual_note'] = [
          '#type' => 'item',
          '#title' => $this->t('Notes'),
          '#markup' => $manual_note,
        ];
      }
      if ($manual_required) {
        $form['stripe_manual']['manual_link'] = [
          '#type' => 'link',
          '#title' => $this->t('Confirm Stripe manual fix'),
          '#url' => Url::fromRoute('storage_manager.assignment_manual_confirm', ['storage_assignment' => $assignment->id()], [
            'query' => ['destination' => '/admin/storage'],
          ]),
          '#attributes' => [
            'class' => ['button', 'button--small'],
          ],
        ];
      }
    }

    if ($assignment->hasField('field_storage_complimentary') && $this->currentUser()->hasPermission('manage storage')) {
      $form['billing'] = [
        '#type' => 'details',
        '#title' => $this->t('Billing'),
        '#open' => TRUE,
      ];
      $form['billing']['assignment_complimentary'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Complimentary assignment (no monthly cost)'),
        '#default_value' => (int) $assignment->get('field_storage_complimentary')->value,
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];

    if ($active_violation) {
      $form_state->set('storage_manager_active_violation_id', $active_violation->id());
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\eck\EckEntityInterface|null $assignment */
    $assignment = $form_state->getValue('assignment');
    if (!$assignment instanceof EckEntityInterface) {
      $this->messenger()->addError($this->t('Unable to load the storage assignment.'));
      return;
    }

    $assignment->set('field_storage_issue_note', $form_state->getValue('assignment_note'));

    if ($assignment->hasField('field_storage_complimentary') && $this->currentUser()->hasPermission('manage storage')) {
      $assignment->set('field_storage_complimentary', $form_state->getValue('assignment_complimentary') ? 1 : 0);
    }

    $assignment->save();

    $violation_storage = $this->entityTypeManager->getStorage('storage_violation');
    $active_id = $form_state->get('storage_manager_active_violation_id');

    if ($active_id) {
      /** @var \Drupal\eck\EckEntityInterface|null $violation */
      $violation = $violation_storage->load($active_id);
      if ($violation) {
        $daily = $form_state->getValue('active_violation_daily_rate');
        $daily = $daily === '' || $daily === NULL ? NULL : (float) $daily;
        $violation->set('field_storage_vi_daily', $daily);
        $violation->set('field_storage_vi_note', $form_state->getValue('active_violation_note'));
        if ($form_state->getValue('active_violation_resolve')) {
          $resolved_input = $form_state->getValue('active_violation_resolved_date');
          $resolved_dt = $resolved_input instanceof DrupalDateTime ? $resolved_input->getPhpDateTime() : NULL;
          $total = $this->violationManager->finalizeViolation($violation, $resolved_dt);
          $this->messenger()->addStatus($this->t('Violation resolved with $@amount due.', ['@amount' => number_format($total, 2)]));

          $context = [
            'assignment' => $assignment,
            'violation' => $violation,
            'violation_total' => $total,
            'violation_resolved' => $violation->get('field_storage_vi_resolved')->value,
          ];
          $this->notificationManager->sendEvent('violation_resolved', $context);
          if ($total > 0) {
            $this->notificationManager->sendEvent('violation_fine', $context);
          }
        }
        else {
          $violation->save();
          $this->messenger()->addStatus($this->t('Active violation updated.'));
        }
      }
    }
    elseif ($form_state->getValue('start_violation_trigger')) {
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
    }

    $this->messenger()->addStatus($this->t('Assignment has been updated.'));
  }

  protected function buildActiveViolationSection(EckEntityInterface $violation): array {
    $start = $violation->get('field_storage_vi_start')->value;
    $resolved = $violation->get('field_storage_vi_resolved')->value;
    $daily = $violation->get('field_storage_vi_daily')->value;
    $accrued = $this->violationManager->calculateAccruedCharge($violation);

    return [
      '#type' => 'details',
      '#title' => $this->t('Active violation'),
      '#open' => TRUE,
      'active_violation_id' => [
        '#type' => 'value',
        '#value' => $violation->id(),
      ],
      'summary' => [
        '#type' => 'item',
        '#markup' => $this->t('Started @start', ['@start' => $this->formatDate($start)]),
      ],
      'active_violation_daily_rate' => [
        '#type' => 'number',
        '#title' => $this->t('Daily charge'),
        '#default_value' => $daily !== NULL && $daily !== '' ? $daily : $this->violationManager->getDefaultDailyRate(),
        '#step' => '0.01',
        '#min' => '0',
        '#description' => $this->t('Amount billed for each day the violation remains active.'),
      ],
      'active_violation_accrued' => [
        '#type' => 'item',
        '#title' => $this->t('Accrued total (to date)'),
        '#markup' => $this->t('$@amount', ['@amount' => number_format($accrued, 2)]),
      ],
      'active_violation_note' => [
        '#type' => 'textarea',
        '#title' => $this->t('Violation notes'),
        '#default_value' => $violation->get('field_storage_vi_note')->value,
      ],
      'active_violation_resolve' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Mark violation as resolved'),
      ],
      'active_violation_resolved_date' => [
        '#type' => 'datetime',
        '#title' => $this->t('Resolved on'),
        '#default_value' => $resolved ? new DrupalDateTime($resolved) : new DrupalDateTime('now'),
        '#date_time_element' => 'none',
        '#states' => [
          'visible' => [
            ':input[name="active_violation_resolve"]' => ['checked' => TRUE],
          ],
        ],
      ],
    ];
  }

  protected function buildStartViolationSection(): array {
    $default_rate = $this->violationManager->getDefaultDailyRate();

    return [
      '#type' => 'details',
      '#title' => $this->t('Start violation'),
      '#open' => TRUE,
      'start_violation_start' => [
        '#type' => 'datetime',
        '#title' => $this->t('Violation start'),
        '#default_value' => new DrupalDateTime('now'),
        '#date_time_element' => 'none',
      ],
      'start_violation_daily_rate' => [
        '#type' => 'number',
        '#title' => $this->t('Daily charge'),
        '#default_value' => $default_rate,
        '#step' => '0.01',
        '#min' => '0',
      ],
      'start_violation_note' => [
        '#type' => 'textarea',
        '#title' => $this->t('Violation notes'),
      ],
      'start_violation_trigger' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Start a new violation with the details above'),
      ],
    ];
  }

  protected function buildViolationHistory(array $violations): array {
    $rows = [];
    foreach ($violations as $violation) {
      $status = $this->violationManager->isViolationActive($violation)
        ? $this->t('Active')
        : $this->t('Resolved');
      $rows[] = [
        $status,
        $this->formatDate($violation->get('field_storage_vi_start')->value),
        $this->formatDate($violation->get('field_storage_vi_resolved')->value),
        '$' . number_format((float) ($violation->get('field_storage_vi_daily')->value ?? $this->violationManager->getDefaultDailyRate()), 2),
        '$' . number_format((float) ($violation->get('field_storage_vi_total')->value ?? 0), 2),
        $violation->get('field_storage_vi_note')->value,
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Status'),
        $this->t('Start'),
        $this->t('Resolved'),
        $this->t('Daily rate'),
        $this->t('Total due'),
        $this->t('Notes'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No violation history recorded.'),
    ];
  }

  protected function formatDate($value): string {
    if ($value instanceof \DateTimeInterface) {
      return $value->format('Y-m-d');
    }
    if (is_string($value) && $value !== '') {
      $timestamp = strtotime($value);
      if ($timestamp) {
        return date('Y-m-d', $timestamp);
      }
    }
    return '';
  }
}

<?php

namespace Drupal\storage_manager\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\eck\EckEntityInterface;
use Drupal\storage_manager\Service\ViolationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

class EditAssignmentForm extends FormBase {

  public function __construct(
    private readonly ViolationManager $violationManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('storage_manager.violation_manager'),
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
    $destination = Url::fromRoute('storage_manager.assignment_edit', ['storage_assignment' => $assignment->id()])->toString();
    $violation_url = Url::fromRoute('storage_manager.start_violation', ['storage_assignment' => $assignment->id()], [
      'query' => ['destination' => $destination],
    ]);
    $violation_link = Link::fromTextAndUrl($this->t('Open violation manager'), $violation_url)->toString();

    $violation_message = $this->t('Manage violations for this assignment on the dedicated violation form. !link', ['!link' => $violation_link]);
    $violation_message_class = 'messages--info';
    if ($active_violation) {
      $violation_message = $this->t('An active violation (started @start) exists for this assignment. Manage it on the dedicated violation form. !link', [
        '@start' => $this->formatDate($active_violation->get('field_storage_vi_start')->value),
        '!link' => $violation_link,
      ]);
      $violation_message_class = 'messages--warning';
    }

    $form['violation_notice'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', $violation_message_class]],
      'text' => ['#markup' => $violation_message],
    ];
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

    $this->messenger()->addStatus($this->t('Assignment has been updated.'));
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

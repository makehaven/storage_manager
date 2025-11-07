<?php

namespace Drupal\storage_manager\Form;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\storage_manager\Service\NotificationManager;
use Drupal\storage_manager\Service\StripeAssignmentManager;
use Drupal\storage_manager\Service\ViolationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ReleaseForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly NotificationManager $notificationManager,
    private readonly ViolationManager $violationManager,
    private readonly StripeAssignmentManager $stripeAssignmentManager,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('storage_manager.notification_manager'),
      $container->get('storage_manager.violation_manager'),
      $container->get('storage_manager.stripe_assignment_manager'),
      $container->get('cache_tags.invalidator'),
    );
  }

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

    $a_storage = $this->entityTypeManager->getStorage('storage_assignment');
    $ids = $a_storage->getQuery()
      ->condition('field_storage_unit', $unit_id)
      ->condition('field_storage_assignment_status', 'active')
      ->accessCheck(FALSE)
      ->execute();

    if ($ids) {
      $assignment = $a_storage->load(reset($ids));
      $assignment->set('field_storage_assignment_status', 'ended');
      $assignment->set('field_storage_end_date', $form_state->getValue('end_date'));

      $violation_total = NULL;
      $active_violation = $this->violationManager->loadActiveViolation((int) $assignment->id());
      if ($active_violation) {
        $resolved_value = $form_state->getValue('end_date');
        $resolved_dt = NULL;
        if (!empty($resolved_value)) {
          $resolved_date = new DrupalDateTime($resolved_value . ' 23:59:59');
          $resolved_dt = $resolved_date->getPhpDateTime();
        }
        $violation_total = $this->violationManager->finalizeViolation($active_violation, $resolved_dt);
      }

      $assignment->save();

      $context = [
        'assignment' => $assignment,
        'release_date' => $form_state->getValue('end_date'),
      ];
      $this->notificationManager->sendEvent('release', $context);

      if ($violation_total !== NULL) {
        if ($active_violation) {
          $context['violation'] = $active_violation;
          $context['violation_resolved'] = $active_violation->get('field_storage_vi_resolved')->value;
        }
        $context['violation_total'] = $violation_total;
        $this->notificationManager->sendEvent('violation_resolved', $context);
        if ($violation_total > 0) {
          $this->notificationManager->sendEvent('violation_fine', $context);
        }
      }

      if ($this->stripeAssignmentManager->isEnabled()) {
        try {
          $this->stripeAssignmentManager->releaseAssignment($assignment);
        }
        catch (\Throwable $e) {
          $this->logger('storage_manager')->error('Failed to release Stripe billing for storage assignment @id: @message', [
            '@id' => $assignment->id(),
            '@message' => $e->getMessage(),
          ]);
          $this->messenger()->addWarning($this->t('Storage billing was not fully released in Stripe. Please review the subscription manually.'));
        }
      }
    }

    $u_storage = $this->entityTypeManager->getStorage('storage_unit');
    if ($unit = $u_storage->load($unit_id)) {
      $unit->set('field_storage_status', 'vacant');
      $unit->save();
    }

    if (isset($violation_total) && $violation_total > 0) {
      $this->messenger()->addWarning($this->t('Violation finalized with $@amount due.', ['@amount' => number_format($violation_total, 2)]));
    }

    $this->messenger()->addStatus($this->t('Released unit.'));
    $this->cacheTagsInvalidator->invalidateTags(['storage_assignment_list']);
    $form_state->setRedirectUrl(Url::fromRoute('storage_manager.dashboard'));
  }
}

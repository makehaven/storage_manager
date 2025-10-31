<?php

namespace Drupal\storage_manager\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\eck\EckEntityInterface;
use Drupal\storage_manager\Service\StripeAssignmentManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class StripeManualConfirmForm extends ConfirmFormBase {

  protected ?EckEntityInterface $assignment = NULL;

  public function __construct(
    private readonly StripeAssignmentManager $stripeAssignmentManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('storage_manager.stripe_assignment_manager'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'storage_manager_stripe_manual_confirm_form';
  }

  public function getQuestion(): string {
    $assignment = $this->getAssignment();
    return $this->t('Confirm manual Stripe follow-up for assignment @id?', ['@id' => $assignment?->id()]);
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('storage_manager.dashboard');
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EckEntityInterface $storage_assignment = NULL): array {
    if ($storage_assignment instanceof EckEntityInterface) {
      $this->assignment = $storage_assignment;
    }
    $assignment = $this->getAssignment();
    if (!$assignment instanceof EckEntityInterface) {
      throw new \InvalidArgumentException('Unable to load storage assignment.');
    }

    $form = parent::buildForm($form, $form_state);

    $note = $assignment->hasField('field_stripe_manual_note')
      ? (string) ($assignment->get('field_stripe_manual_note')->value ?? '')
      : '';
    $manualRequired = $assignment->hasField('field_stripe_manual_review')
      ? (bool) ($assignment->get('field_stripe_manual_review')->value ?? FALSE)
      : FALSE;

    $form['current_note'] = [
      '#type' => 'item',
      '#title' => $this->t('Current note'),
      '#markup' => $note !== '' ? $this->t('@note', ['@note' => $note]) : $this->t('No details recorded.'),
    ];

    if (!$manualRequired) {
      $form['info'] = [
        '#type' => 'item',
        '#markup' => $this->t('This assignment is not currently flagged for manual review, but you can store an updated note if needed.'),
      ];
    }

    $form['confirmation_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Confirmation notes'),
      '#default_value' => $manualRequired ? $note : '',
      '#description' => $this->t('Describe the manual Stripe updates performed or add any notes for the team. This replaces the current note.'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $assignment = $this->getAssignment();
    if (!$assignment instanceof EckEntityInterface) {
      $this->messenger()->addError($this->t('Unable to load the storage assignment.'));
      return;
    }

    $note = trim((string) $form_state->getValue('confirmation_note'));
    $cleared = $this->stripeAssignmentManager->clearManualReview($assignment, $note);

    if ($cleared) {
      try {
        $assignment->save();
        $this->messenger()->addStatus($this->t('Manual Stripe follow-up recorded and the assignment is no longer flagged.'));
      }
      catch (\Throwable $e) {
        $this->messenger()->addError($this->t('The assignment could not be saved: @message', ['@message' => $e->getMessage()]));
      }
    }
    else {
      $this->messenger()->addStatus($this->t('No changes were required for the assignment.'));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  protected function getAssignment(): ?EckEntityInterface {
    if ($this->assignment instanceof EckEntityInterface) {
      return $this->assignment;
    }

    $route_param = \Drupal::routeMatch()->getParameter('storage_assignment');
    if ($route_param instanceof EckEntityInterface) {
      $this->assignment = $route_param;
      return $this->assignment;
    }

    if (is_numeric($route_param)) {
      $storage = $this->entityTypeManager->getStorage('storage_assignment');
      $this->assignment = $storage->load((int) $route_param);
    }
    return $this->assignment;
  }

}

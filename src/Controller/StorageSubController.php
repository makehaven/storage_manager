<?php

namespace Drupal\storage_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Url;
use Drupal\mh_stripe\Service\StripeHelper;
use Drupal\storage_manager\Service\StripeAssignmentManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class StorageSubController extends ControllerBase {

  public function __construct(
    private readonly StripeHelper $stripeHelper,
    private readonly StripeAssignmentManager $stripeAssignmentManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('mh_stripe.helper'),
      $container->get('storage_manager.stripe_assignment_manager'),
    );
  }

  /**
   * Create or open a Stripe subscription for a storage assignment.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $assignment
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public function createOrOpen(ContentEntityInterface $assignment): RedirectResponse {
    // Permission check.
    if (!$this->currentUser()->hasPermission('manage storage billing')) {
      throw new AccessDeniedHttpException();
    }

    // Validate entity type.
    if ($assignment->getEntityTypeId() !== 'storage_assignment') {
      throw new NotFoundHttpException('Invalid entity.');
    }

    // Look up the referenced user on the assignment.
    $account = $assignment->get('user_id')->entity ?? NULL;
    if (!$account) {
      $this->messenger()->addError($this->t('This assignment has no user set.'));
      return $this->redirect('entity.storage_assignment.canonical', ['storage_assignment' => $assignment->id()]);
    }

    // Get Stripe customer id from the user.
    $customer_field = $this->stripeHelper->customerFieldName();
    $customer_id = (string) ($account->get($customer_field)->value ?? '');
    if (!$customer_id && !$account->getEmail()) {
      $this->messenger()->addError($this->t('This user does not have an email address. Add one before syncing the storage subscription with Stripe.'));
      return new RedirectResponse(Url::fromRoute('entity.user.canonical', ['user' => $account->id()])->toString());
    }

    // Ensure the assignment is synchronized with Stripe.
    try {
      $this->stripeAssignmentManager->syncAssignment($assignment);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Unable to synchronize this assignment with Stripe: @msg', ['@msg' => $e->getMessage()]));
      return $this->redirect('entity.storage_assignment.edit_form', ['storage_assignment' => $assignment->id()]);
    }

    /** @var \Drupal\eck\EckEntityInterface|null $refreshed */
    $refreshed = $this->entityTypeManager()->getStorage('storage_assignment')->load($assignment->id());
    $assignment = $refreshed ?? $assignment;

    // Open subscription in Stripe if available.
    $sub_field = 'field_storage_stripe_sub_id';
    $subscription_id = (string) ($assignment->get($sub_field)->value ?? '');
    if (!$subscription_id) {
      $this->messenger()->addError($this->t('Stripe billing is not linked to this assignment yet. Check the assignment’s price settings and try again.'));
      return $this->redirect('entity.storage_assignment.edit_form', ['storage_assignment' => $assignment->id()]);
    }

    $this->messenger()->addStatus($this->t('Stripe subscription is ready. Opening dashboard in a new tab.'));
    return new RedirectResponse($this->stripeHelper->subscriptionDashboardUrl($subscription_id));
  }
}

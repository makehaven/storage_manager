<?php

namespace Drupal\storage_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Url;
use Drupal\mh_stripe\Service\StripeHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class StorageSubController extends ControllerBase {

  public function __construct(private StripeHelper $stripeHelper) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('mh_stripe.helper'));
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
    $customer_id = (string) ($account->get('field_stripe_customer_id')->value ?? '');
    if (!$customer_id) {
      $this->messenger()->addError($this->t('No Stripe customer linked to this user. Open their profile and use "Open in Stripe" first.'));
      return new RedirectResponse(Url::fromRoute('entity.user.canonical', ['user' => $account->id()])->toString());
    }

    // If already linked, open subscription in Stripe.
    $sub_field = 'field_storage_stripe_sub_id';
    $existing_sub = (string) ($assignment->get($sub_field)->value ?? '');
    if ($existing_sub) {
      return new RedirectResponse($this->stripeHelper->subscriptionDashboardUrl($existing_sub));
    }

    // Require a price id on the assignment.
    $price_id = (string) ($assignment->get('field_stripe_price_id')->value ?? '');
    if (!$price_id) {
      $this->messenger()->addError($this->t('No Stripe price configured on this assignment.'));
      return $this->redirect('entity.storage_assignment.edit_form', ['storage_assignment' => $assignment->id()]);
    }

    // Create subscription and save sub id.
    try {
      $metadata = [
        'drupal_assignment_id' => (string) $assignment->id(),
        'storage_unit' => (string) ($assignment->get('field_unit_label')->value ?? ''),
      ];
      $sub = $this->stripeHelper->createSubscription($customer_id, $price_id, $metadata);
      $assignment->set($sub_field, $sub['id'])->save();

      $this->messenger()->addStatus($this->t('Stripe subscription created and linked.'));
      return new RedirectResponse($this->stripeHelper->subscriptionDashboardUrl($sub['id']));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Error creating Stripe subscription: @msg', ['@msg' => $e->getMessage()]));
      return $this->redirect('entity.storage_assignment.edit_form', ['storage_assignment' => $assignment->id()]);
    }
  }
}

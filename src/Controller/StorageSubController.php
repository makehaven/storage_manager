<?php

namespace Drupal\storage_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Url;
use Drupal\mh_stripe\Service\StripeHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for staff-facing subscription management.
 */
class StorageSubController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The mh_stripe helper service.
   *
   * @var \Drupal\mh_stripe\Service\StripeHelper
   */
  protected $stripeHelper;

  /**
   * Constructs a new StorageSubController object.
   *
   * @param \Drupal\mh_stripe\Service\StripeHelper $stripe_helper
   *   The mh_stripe helper service.
   */
  public function __construct(StripeHelper $stripe_helper) {
    $this->stripeHelper = $stripe_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('mh_stripe.helper')
    );
  }

  /**
   * Create or open a Stripe subscription for a storage assignment.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $assignment
   *   The storage assignment.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the Stripe dashboard.
   */
  public function createOrOpen(ContentEntityInterface $assignment) {
    if ($sub_id = $assignment->field_storage_stripe_sub_id->value) {
      return new RedirectResponse($this->stripeHelper->subscriptionDashboardUrl($sub_id));
    }

    $user = $assignment->field_storage_user->entity;
    if (!$user) {
      $this->messenger()->addError($this->t('Assignment must have a user.'));
      return $this->redirect('<front>');
    }

    $customer_id = $user->field_stripe_customer_id->value;
    if (!$customer_id) {
      $this->messenger()->addError($this->t('User does not have a Stripe customer ID. Please create one from the user admin page.'));
      return $this->redirect('entity.user.edit_form', ['user' => $user->id()]);
    }

    $price_id = $assignment->field_stripe_price_id->value;
    if (!$price_id) {
      $this->messenger()->addError($this->t('Assignment must have a Stripe price ID.'));
      return $this->redirect('entity.storage_assignment.edit_form', ['storage_assignment' => $assignment->id()]);
    }

    $unit = $assignment->field_storage_unit->entity;
    $metadata = [
      'drupal_assignment_id' => $assignment->id(),
      'storage_unit' => $unit ? $unit->label() : 'N/A',
    ];

    try {
      $subscription = $this->stripeHelper->createSubscription($customer_id, $price_id, $metadata);
      $assignment->field_storage_stripe_sub_id->value = $subscription['id'];
      $assignment->save();

      $this->messenger()->addStatus($this->t('Stripe subscription created.'));
      return new RedirectResponse($this->stripeHelper->subscriptionDashboardUrl($subscription['id']));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('There was an error creating the Stripe subscription: @message', ['@message' => $e->getMessage()]));
      return $this->redirect('entity.storage_assignment.edit_form', ['storage_assignment' => $assignment->id()]);
    }
  }

}

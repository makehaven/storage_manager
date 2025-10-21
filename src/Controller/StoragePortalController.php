<?php

namespace Drupal\storage_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\mh_stripe\Service\StripeHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for member-facing billing portal.
 */
class StoragePortalController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The mh_stripe helper service.
   *
   * @var \Drupal\mh_stripe\Service\StripeHelper
   */
  protected $stripeHelper;

  /**
   * Constructs a new StoragePortalController object.
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
   * Redirects the current user to the Stripe Billing Portal.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the Stripe Billing Portal.
   */
  public function portal() {
    $user = $this->currentUser();
    $customer_id = $user->get('field_stripe_customer_id')->value;

    if (!$customer_id) {
      $this->messenger()->addError($this->t('We could not find your billing information. Please contact staff for assistance.'));
      return $this->redirect('<front>');
    }

    $return_url = Url::fromRoute('storage_manager.member_dashboard', [], ['absolute' => TRUE])->toString();
    $portal_url = $this->stripeHelper->createPortalUrl($customer_id, $return_url, 'invoices');

    return new RedirectResponse($portal_url);
  }

}

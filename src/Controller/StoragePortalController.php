<?php

namespace Drupal\storage_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\mh_stripe\Service\StripeHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class StoragePortalController extends ControllerBase {

  public function __construct(private StripeHelper $stripeHelper) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('mh_stripe.helper'));
  }

  public function portal(): RedirectResponse {
    if ($this->currentUser()->isAnonymous()) {
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }
    $uid = (int) $this->currentUser()->id();
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);

    $customer_id = (string) ($user->get('field_stripe_customer_id')->value ?? '');
    if (!$customer_id) {
      $this->messenger()->addError($this->t('We could not find your billing information. Please contact staff.'));
      return $this->redirect('<front>');
    }

    $return_url = Url::fromRoute('entity.user.canonical', ['user' => $uid], ['absolute' => TRUE])->toString();
    // Pass NULL to use invoices-only configuration from settings if present.
    $portal_url = $this->stripeHelper->createPortalUrl($customer_id, $return_url, NULL);

    return new RedirectResponse($portal_url);
  }
}

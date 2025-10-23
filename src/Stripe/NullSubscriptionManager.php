<?php

namespace Drupal\storage_manager\Stripe;

/**
 * A null implementation of the SubscriptionManager.
 */
class NullSubscriptionManager {

  /**
   * {@inheritdoc}
   */
  public function createSubscription(string $stripeCustomerId, string $stripePriceId): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function cancelSubscription(string $subscriptionId, bool $atPeriodEnd = TRUE): void {
  }

}

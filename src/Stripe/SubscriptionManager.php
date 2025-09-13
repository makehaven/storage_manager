<?php

namespace Drupal\storage_manager\Stripe;

use Psr\Log\LoggerInterface;

class SubscriptionManager {
  public function __construct(private LoggerInterface $logger) {}

  public function createSubscription(string $stripeCustomerId, string $stripePriceId): ?string {
    // Stub: integrate stripe/stripe-php here when ready.
    $this->logger->info('Would create Stripe subscription for customer @c with price @p', [
      '@c' => $stripeCustomerId, '@p' => $stripePriceId,
    ]);
    return NULL;
  }

  public function cancelSubscription(string $subscriptionId, bool $atPeriodEnd = TRUE): void {
    // Stub
    $this->logger->info('Would cancel Stripe subscription @s (at_period_end=@e)', [
      '@s' => $subscriptionId, '@e' => $atPeriodEnd ? 'true' : 'false',
    ]);
  }
}

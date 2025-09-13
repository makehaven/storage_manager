<?php

namespace Drupal\storage_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends ControllerBase {
  public function handle(Request $request): Response {
    // TODO: Verify signature with your Stripe webhook secret header before using.
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload) || empty($payload['type'])) {
      return new Response('bad request', 400);
    }

    // Examples:
    // - customer.subscription.updated / deleted
    // - invoice.payment_failed
    // Use `field_stripe_subscription_id` to find the assignment and update.
    $this->logger('storage_manager')->info('Webhook: @type', ['@type' => $payload['type']]);

    return new Response('ok', 200);
  }
}

<?php

namespace Drupal\storage_manager\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\eck\EckEntityInterface;
use Drupal\mh_stripe\Service\StripeHelper;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Subscription;
use Stripe\SubscriptionItem;

/**
 * Coordinates Stripe subscriptions for storage assignments.
 */
final class StripeAssignmentManager {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?StripeHelper $stripeHelper,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns TRUE when Stripe billing is enabled for storage manager.
   */
  public function isEnabled(): bool {
    if ($this->stripeHelper === NULL) {
      return FALSE;
    }
    return (bool) $this->configFactory->get('storage_manager.settings')->get('stripe.enable_billing');
  }

  /**
   * Ensure Stripe reflects the current state of an active assignment.
   */
  public function syncAssignment(EckEntityInterface $assignment): void {
    if (!$this->isEnabled() || $assignment->getEntityTypeId() !== 'storage_assignment') {
      return;
    }

    $status = (string) ($assignment->get('field_storage_assignment_status')->value ?? '');
    if ($status !== 'active') {
      return;
    }

    if ($assignment->hasField('field_storage_complimentary') && (bool) $assignment->get('field_storage_complimentary')->value) {
      // Complimentary assignments are not billed through Stripe.
      return;
    }

    $user = $assignment->get('field_storage_user')->entity;
    if (!$user instanceof UserInterface) {
      $this->logger->warning('Unable to sync storage assignment @id with Stripe because the member record is missing.', ['@id' => $assignment->id()]);
      return;
    }

    $priceId = $this->resolvePriceId($assignment);
    if ($priceId === '') {
      $this->logger->warning('Storage assignment @id does not have a Stripe price configured; skipping billing setup.', ['@id' => $assignment->id()]);
      throw new \RuntimeException('A Stripe price must be configured before Stripe billing can be synchronized.');
    }

    $customerId = $this->ensureCustomerId($user);
    if ($customerId === '') {
      $this->logger->warning('Unable to find or create a Stripe customer for user @uid when syncing storage assignment @id.', [
        '@uid' => $user->id(),
        '@id' => $assignment->id(),
      ]);
      throw new \RuntimeException('Unable to locate a Stripe customer for this member.');
    }

    $metadata = $this->buildMetadata($assignment);
    $subscriptionId = $assignment->hasField('field_storage_stripe_sub_id')
      ? trim((string) ($assignment->get('field_storage_stripe_sub_id')->value ?? ''))
      : '';
    $subscriptionItemId = $assignment->hasField('field_storage_stripe_item_id')
      ? trim((string) ($assignment->get('field_storage_stripe_item_id')->value ?? ''))
      : '';

    if ($subscriptionId === '') {
      $existing = $this->findExistingSubscriptionId($assignment);
      if ($existing !== NULL) {
        $subscriptionId = $existing;
        if ($assignment->hasField('field_storage_stripe_sub_id')) {
          $assignment->set('field_storage_stripe_sub_id', $subscriptionId);
        }
      }
    }

    try {
      $client = $this->stripeHelper->client();

      if ($subscriptionId === '') {
        $subscription = $client->subscriptions->create([
          'customer' => $customerId,
          'items' => [
            [
              'price' => $priceId,
              'metadata' => $metadata,
            ],
          ],
          'metadata' => [
            'storage_manager_managed' => '1',
            'storage_assignment_id' => (string) $assignment->id(),
          ],
          'expand' => ['items.data'],
        ]);
        if ($assignment->hasField('field_storage_stripe_sub_id')) {
          $assignment->set('field_storage_stripe_sub_id', $subscription->id);
        }
        if ($assignment->hasField('field_storage_stripe_item_id')) {
          $assignment->set('field_storage_stripe_item_id', $subscription->items->data[0]->id ?? '');
        }
        if ($assignment->hasField('field_storage_stripe_status')) {
          $assignment->set('field_storage_stripe_status', $subscription->status ?? '');
        }
      }
      else {
        $subscription = $client->subscriptions->retrieve($subscriptionId, ['expand' => ['items.data']]);
        $item = $this->resolveSubscriptionItem($client, $subscription, $subscriptionItemId, $assignment);

        if ($item) {
          $client->subscriptionItems->update($item->id, [
            'price' => $priceId,
            'metadata' => $metadata,
          ]);
        }
        else {
          $item = $client->subscriptionItems->create([
            'subscription' => $subscriptionId,
            'price' => $priceId,
            'metadata' => $metadata,
          ]);
        }

        if ($assignment->hasField('field_storage_stripe_item_id')) {
          $assignment->set('field_storage_stripe_item_id', $item->id ?? '');
        }
        $refreshed = $client->subscriptions->retrieve($subscriptionId);
        if ($assignment->hasField('field_storage_stripe_status')) {
          $assignment->set('field_storage_stripe_status', $refreshed->status ?? '');
        }
      }

      if ($assignment->isDirty()) {
        $assignment->save();
      }
    }
    catch (ApiErrorException $e) {
      $this->logger->error('Stripe API error while syncing storage assignment @id: @message', [
        '@id' => $assignment->id(),
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException($e->getMessage(), 0, $e);
    }
    catch (\Throwable $e) {
      $this->logger->error('Unexpected error while syncing storage assignment @id: @message', [
        '@id' => $assignment->id(),
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Remove a storage assignment's Stripe subscription or subscription item.
   */
  public function releaseAssignment(EckEntityInterface $assignment): void {
    if (!$this->isEnabled() || $assignment->getEntityTypeId() !== 'storage_assignment') {
      return;
    }

    $subscriptionId = $assignment->hasField('field_storage_stripe_sub_id')
      ? trim((string) ($assignment->get('field_storage_stripe_sub_id')->value ?? ''))
      : '';
    if ($subscriptionId === '') {
      return;
    }

    $subscriptionItemId = $assignment->hasField('field_storage_stripe_item_id')
      ? trim((string) ($assignment->get('field_storage_stripe_item_id')->value ?? ''))
      : '';

    try {
      $client = $this->stripeHelper->client();

      if ($subscriptionItemId !== '') {
        $client->subscriptionItems->delete($subscriptionItemId, []);
      }
      else {
        $subscription = $client->subscriptions->retrieve($subscriptionId, ['expand' => ['items.data']]);
        $item = $this->findSubscriptionItem($subscription, (string) $assignment->id());
        if ($item) {
          $client->subscriptionItems->delete($item->id, []);
        }
      }

      $subscription = $client->subscriptions->retrieve($subscriptionId, ['expand' => ['items.data']]);
      $remaining = $this->filterStorageItems($subscription, (string) $assignment->id());
      if (empty($remaining) && ($subscription->metadata['storage_manager_managed'] ?? '') === '1') {
        $client->subscriptions->cancel($subscriptionId, []);
        if ($assignment->hasField('field_storage_stripe_sub_id')) {
          $assignment->set('field_storage_stripe_sub_id', '');
        }
      }

      if ($assignment->hasField('field_storage_stripe_item_id')) {
        $assignment->set('field_storage_stripe_item_id', '');
      }
      if ($assignment->hasField('field_storage_stripe_status')) {
        $assignment->set('field_storage_stripe_status', 'canceled');
      }

      if ($assignment->isDirty()) {
        $assignment->save();
      }
    }
    catch (ApiErrorException $e) {
      $this->logger->error('Stripe API error while releasing storage assignment @id: @message', [
        '@id' => $assignment->id(),
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException($e->getMessage(), 0, $e);
    }
    catch (\Throwable $e) {
      $this->logger->error('Unexpected error while releasing storage assignment @id: @message', [
        '@id' => $assignment->id(),
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Ensure the assignment has a usable price ID, filling defaults when needed.
   */
  protected function resolvePriceId(EckEntityInterface $assignment): string {
    $price = $assignment->hasField('field_stripe_price_id')
      ? trim((string) ($assignment->get('field_stripe_price_id')->value ?? ''))
      : '';
    if ($price !== '') {
      return $price;
    }

    // Attempt to pull from the storage type taxonomy term.
    $unit = $assignment->hasField('field_storage_unit')
      ? $assignment->get('field_storage_unit')->entity
      : NULL;
    if ($unit && $unit->hasField('field_storage_type')) {
      $type = $unit->get('field_storage_type')->entity;
      if ($type && $type->hasField('field_stripe_price_id')) {
        $type_price = trim((string) ($type->get('field_stripe_price_id')->value ?? ''));
        if ($type_price !== '') {
          if ($assignment->hasField('field_stripe_price_id')) {
            $assignment->set('field_stripe_price_id', $type_price);
          }
          return $type_price;
        }
      }
    }

    $default = trim((string) $this->configFactory->get('storage_manager.settings')->get('stripe.default_price_id'));
    if ($default !== '' && $assignment->hasField('field_stripe_price_id')) {
      $assignment->set('field_stripe_price_id', $default);
      return $default;
    }

    return '';
  }

  /**
   * Ensure the user has a Stripe customer record and return the ID.
   */
  protected function ensureCustomerId(UserInterface $user): string {
    if ($this->stripeHelper === NULL) {
      return '';
    }

    $field = $this->stripeHelper->customerFieldName();
    $customerId = trim((string) ($user->get($field)->value ?? ''));
    if ($customerId !== '') {
      return $customerId;
    }

    $email = $user->getEmail();
    if (!$email) {
      return '';
    }

    try {
      $customerId = $this->stripeHelper->findOrCreateCustomerIdByEmail($email, [
        'metadata' => [
          'drupal_uid' => (string) $user->id(),
        ],
      ]);
      $user->set($field, $customerId);
      $user->save();
      return $customerId;
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to create a Stripe customer for user @uid: @message', [
        '@uid' => $user->id(),
        '@message' => $e->getMessage(),
      ]);
      return '';
    }
  }

  /**
   * Attempt to re-use another active storage subscription for the same user.
   */
  protected function findExistingSubscriptionId(EckEntityInterface $assignment): ?string {
    $user = $assignment->get('field_storage_user')->entity;
    if (!$user instanceof UserInterface) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('storage_assignment');
    $query = $storage->getQuery()
      ->condition('id', $assignment->id(), '<>')
      ->condition('field_storage_user', $user->id())
      ->condition('field_storage_assignment_status', 'active')
      ->condition('field_storage_stripe_sub_id', '', '<>')
      ->accessCheck(FALSE)
      ->range(0, 1);

    $ids = $query->execute();
    if (!$ids) {
      return NULL;
    }

    /** @var \Drupal\eck\EckEntityInterface $other */
    $other = $storage->load(reset($ids));
    if ($other && !$other->get('field_storage_stripe_sub_id')->isEmpty()) {
      return (string) $other->get('field_storage_stripe_sub_id')->value;
    }

    return NULL;
  }

  /**
   * Build metadata for subscription line items.
   */
  protected function buildMetadata(EckEntityInterface $assignment): array {
    $unit = $assignment->get('field_storage_unit')->entity;
    $unitLabel = $unit?->get('field_storage_unit_id')->value ?? $unit?->label() ?? '';

    return array_filter([
      'storage_assignment_id' => (string) $assignment->id(),
      'storage_unit' => $unitLabel,
    ]);
  }

  /**
   * Ensure we have a Stripe subscription item for this assignment.
   */
  protected function resolveSubscriptionItem(StripeClient $client, Subscription $subscription, string $itemId, EckEntityInterface $assignment): ?SubscriptionItem {
    if ($itemId !== '') {
      try {
        return $client->subscriptionItems->retrieve($itemId, []);
      }
      catch (ApiErrorException $e) {
        // Fall through to searching by metadata.
        $this->logger->notice('Stored Stripe subscription item @item for assignment @id could not be retrieved, attempting metadata lookup. Message: @message', [
          '@item' => $itemId,
          '@id' => $assignment->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $this->findSubscriptionItem($subscription, (string) $assignment->id());
  }

  /**
   * Locate a subscription item by assignment metadata.
   */
  protected function findSubscriptionItem(Subscription $subscription, string $assignmentId): ?SubscriptionItem {
    foreach ($subscription->items->data as $item) {
      if (($item->metadata['storage_assignment_id'] ?? '') === $assignmentId) {
        return $item;
      }
    }
    return NULL;
  }

  /**
   * Filter remaining storage-managed items from a subscription.
   */
  protected function filterStorageItems(Subscription $subscription, string $currentAssignmentId): array {
    $items = [];
    foreach ($subscription->items->data as $item) {
      $assignmentId = $item->metadata['storage_assignment_id'] ?? NULL;
      if ($assignmentId && $assignmentId !== $currentAssignmentId) {
        $items[] = $item;
      }
    }
    return $items;
  }

}

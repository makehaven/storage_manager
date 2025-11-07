<?php

namespace Drupal\storage_manager\Service;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\eck\EckEntityInterface;
use Drupal\mh_stripe\Service\StripeHelper;
use Drupal\storage_manager\Service\NotificationManager;
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
    private readonly NotificationManager $notificationManager,
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

    if ($assignment->isTranslatable()) {
      $assignment = $assignment->getUntranslated();
    }

    $status = (string) ($assignment->get('field_storage_assignment_status')->value ?? '');
    if ($status !== 'active') {
      return;
    }

    if ($assignment->hasField('field_storage_complimentary') && (bool) $assignment->get('field_storage_complimentary')->value) {
      $this->logger->notice('Skipping Stripe sync for complimentary assignment @id.', ['@id' => $assignment->id()]);
      return;
    }

    $user = $assignment->get('field_storage_user')->entity;
    if (!$user instanceof UserInterface) {
      $this->logger->warning('Unable to sync storage assignment @id with Stripe because the member record is missing.', ['@id' => $assignment->id()]);
      return;
    }

    $needsSave = FALSE;

    $priceId = $assignment->hasField('field_stripe_price_id')
      ? trim((string) ($assignment->get('field_stripe_price_id')->value ?? ''))
      : '';
    if ($priceId === '') {
      $resolvedPriceId = $this->resolvePriceId($assignment);
      if ($resolvedPriceId !== '') {
        if ($this->setFieldValue($assignment, 'field_stripe_price_id', $resolvedPriceId)) {
          $needsSave = TRUE;
        }
        $priceId = $resolvedPriceId;
      }
    }

    if ($priceId === '') {
      $this->logger->warning('Storage assignment @id does not have a Stripe price configured; skipping billing setup.', ['@id' => $assignment->id()]);
      $this->flagManualReviewRequired($assignment, 'Storage assignment is missing a Stripe price ID.');
      throw new \RuntimeException('A Stripe price must be configured before Stripe billing can be synchronized.');
    }

    $customerId = $this->ensureCustomerId($user);
    if ($customerId === '') {
      $this->logger->warning('Unable to find or create a Stripe customer for user @uid when syncing storage assignment @id.', [
        '@uid' => $user->id(),
        '@id' => $assignment->id(),
      ]);
      $this->flagManualReviewRequired($assignment, 'Unable to locate or create a Stripe customer for this member.');
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
      $existingSubscriptionId = $this->findStorageSubscriptionId($assignment);
      if ($existingSubscriptionId !== NULL) {
        if ($this->setFieldValue($assignment, 'field_storage_stripe_sub_id', $existingSubscriptionId)) {
          $needsSave = TRUE;
        }
        $subscriptionId = $existingSubscriptionId;
      }
    }

    try {
      $client = $this->stripeHelper->client();
      $subscription = NULL;

      if ($subscriptionId !== '') {
        $subscription = $client->subscriptions->retrieve($subscriptionId, ['expand' => ['items.data']]);
        if (!$this->isSubscriptionActiveForStorage($subscription)) {
          $subscription = NULL;
          $subscriptionId = '';
          $subscriptionItemId = '';
        }
      }

      if ($subscriptionId === '') {
        $itemMetadata = $metadata + [
          'storage_manager_assignment' => '1',
          'storage_assignment_ids' => (string) $assignment->id(),
        ];
        $subscription = $client->subscriptions->create([
          'customer' => $customerId,
          'items' => [
            [
              'price' => $priceId,
              'quantity' => 1,
              'metadata' => $itemMetadata,
            ],
          ],
          'metadata' => $this->buildSubscriptionMetadataPayload([(int) $assignment->id()]),
          'expand' => ['items.data'],
        ]);
        if ($this->setFieldValue($assignment, 'field_storage_stripe_sub_id', $subscription->id)) {
          $needsSave = TRUE;
        }
        if ($this->setFieldValue($assignment, 'field_storage_stripe_item_id', $subscription->items->data[0]->id ?? '')) {
          $needsSave = TRUE;
        }
        if ($this->setFieldValue($assignment, 'field_storage_stripe_status', $subscription->status ?? '')) {
          $needsSave = TRUE;
        }
      }
      else {
        $subscription = $subscription ?? $client->subscriptions->retrieve($subscriptionId, ['expand' => ['items.data']]);
        $subscriptionAssignmentIds = $this->collectAssignmentIdsForSubscription($subscription, NULL);
        if (!in_array((int) $assignment->id(), $subscriptionAssignmentIds, TRUE)) {
          $subscriptionAssignmentIds[] = (int) $assignment->id();
        }
        sort($subscriptionAssignmentIds);
        $this->updateSubscriptionAssignmentsMetadata($client, $subscriptionId, $subscriptionAssignmentIds);

        $item = $this->resolveSubscriptionItem($client, $subscription, $subscriptionItemId, $assignment, $priceId);

        if ($item) {
          $itemAssignmentIds = $this->collectAssignmentIdsForItem($subscription, $item, (int) $assignment->id());
          $itemAssignmentIds = array_values(array_unique($itemAssignmentIds));
          if (!in_array((int) $assignment->id(), $itemAssignmentIds, TRUE)) {
            $itemAssignmentIds[] = (int) $assignment->id();
          }
          sort($itemAssignmentIds);

          $priceParam = (($item->price->id ?? '') === $priceId) ? NULL : $priceId;
          $item = $this->updateSubscriptionItemMetadata($client, $item->id, $itemAssignmentIds, $priceParam);
        }
        else {
          $itemMetadata = $metadata + [
            'storage_manager_assignment' => '1',
            'storage_assignment_ids' => (string) $assignment->id(),
          ];
          $item = $client->subscriptionItems->create([
            'subscription' => $subscriptionId,
            'price' => $priceId,
            'quantity' => 1,
            'metadata' => $itemMetadata,
          ]);
        }

        if ($assignment->hasField('field_storage_stripe_item_id')) {
          if ($this->setFieldValue($assignment, 'field_storage_stripe_item_id', $item->id ?? '')) {
            $needsSave = TRUE;
          }
        }
        $refreshed = $client->subscriptions->retrieve($subscriptionId);
        if ($assignment->hasField('field_storage_stripe_status')) {
          if ($this->setFieldValue($assignment, 'field_storage_stripe_status', $refreshed->status ?? '')) {
            $needsSave = TRUE;
          }
        }
      }

      if ($this->clearManualReview($assignment)) {
        $needsSave = TRUE;
      }

      if ($needsSave) {
        $assignment->save();
      }
    }
    catch (ApiErrorException $e) {
      $this->flagManualReviewRequired($assignment, $e->getMessage());
      $this->logger->error('Stripe API error while syncing storage assignment @id: @message', [
        '@id' => $assignment->id(),
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException($e->getMessage(), 0, $e);
    }
    catch (\Throwable $e) {
      $this->flagManualReviewRequired($assignment, $e->getMessage());
      $this->logger->error('Unexpected error while syncing storage assignment @id: @message', [
        '@id' => $assignment->id(),
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Returns the resolved Stripe price ID for an assignment.
   */
  public function getAssignmentPriceId(EckEntityInterface $assignment): string {
    return $this->resolvePriceId($assignment);
  }

  /**
   * Returns the stored Stripe customer ID for a user without creating one.
   */
  public function getStoredCustomerId(UserInterface $user): string {
    if ($this->stripeHelper === NULL) {
      return '';
    }
    $field = $this->stripeHelper->customerFieldName();
    return trim((string) ($user->get($field)->value ?? ''));
  }

  /**
   * Loads all subscriptions for a Stripe customer.
   *
   * @return \Stripe\Subscription[]
   *   The list of subscriptions (may be empty).
   */
  public function loadCustomerSubscriptions(string $customerId): array {
    if ($this->stripeHelper === NULL || $customerId === '') {
      return [];
    }

    try {
      $client = $this->stripeHelper->client();
      $result = $client->subscriptions->all([
        'customer' => $customerId,
        'status' => 'all',
        'limit' => 100,
        'expand' => ['data.items.data'],
      ]);
      $subscriptions = [];
      foreach ($result->autoPagingIterator() as $subscription) {
        $subscriptions[] = $subscription;
      }
      return $subscriptions;
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to load Stripe subscriptions for customer @customer: @message', [
        '@customer' => $customerId,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Link an assignment to an existing Stripe subscription item.
   */
  public function linkAssignmentToSubscriptionItem(EckEntityInterface $assignment, string $subscriptionId, string $itemId): void {
    if (!$this->isEnabled() || $this->stripeHelper === NULL) {
      throw new \RuntimeException('Stripe integration is disabled.');
    }
    if ($subscriptionId === '' || $itemId === '') {
      throw new \InvalidArgumentException('Subscription and item IDs are required.');
    }

    $client = $this->stripeHelper->client();
    $subscription = $client->subscriptions->retrieve($subscriptionId, ['expand' => ['items.data']]);
    $subscriptionItem = NULL;
    foreach ($subscription->items->data as $item) {
      if ($item->id === $itemId) {
        $subscriptionItem = $item;
        break;
      }
    }
    if ($subscriptionItem === NULL) {
      throw new \RuntimeException('Unable to locate the specified subscription item on Stripe.');
    }
    if ($assignment->hasField('field_storage_complimentary') && (bool) $assignment->get('field_storage_complimentary')->value) {
      throw new \RuntimeException('Complimentary assignments cannot be linked to Stripe billing.');
    }

    $assignmentId = (int) $assignment->id();
    $subscriptionAssignments = $this->collectAssignmentIdsForSubscription($subscription, $assignmentId);
    $subscriptionAssignments[] = $assignmentId;
    $subscriptionAssignments = array_values(array_unique($subscriptionAssignments));

    $itemAssignments = $this->collectAssignmentIdsForItem($subscription, $subscriptionItem, $assignmentId);
    $itemAssignments[] = $assignmentId;
    $itemAssignments = array_values(array_unique($itemAssignments));

    $this->updateSubscriptionAssignmentsMetadata($client, $subscriptionId, $subscriptionAssignments);
    $this->updateSubscriptionItemMetadata($client, $itemId, $itemAssignments, $subscriptionItem->price->id ?? NULL);

    if ($assignment->hasField('field_storage_stripe_sub_id')) {
      $assignment->set('field_storage_stripe_sub_id', $subscriptionId);
    }
    if ($assignment->hasField('field_storage_stripe_item_id')) {
      $assignment->set('field_storage_stripe_item_id', $itemId);
    }
    if ($assignment->hasField('field_storage_stripe_status')) {
      $assignment->set('field_storage_stripe_status', $subscription->status ?? '');
    }
    $assignment->save();
  }

  /**
   * Remove a storage assignment's Stripe subscription or subscription item.
   */
  public function releaseAssignment(EckEntityInterface $assignment): void {
    if (!$this->isEnabled() || $assignment->getEntityTypeId() !== 'storage_assignment') {
      return;
    }

    if ($assignment->isTranslatable()) {
      $assignment = $assignment->getUntranslated();
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
    $priceId = $assignment->hasField('field_stripe_price_id')
      ? trim((string) ($assignment->get('field_stripe_price_id')->value ?? ''))
      : '';

    $needsSave = FALSE;

    try {
      $client = $this->stripeHelper->client();
      $subscription = $client->subscriptions->retrieve($subscriptionId, ['expand' => ['items.data']]);
      $managedByStorageManager = ($subscription->metadata['storage_manager_managed'] ?? '') === '1';
      $subscriptionAssignmentIds = $this->collectAssignmentIdsForSubscription($subscription, (int) $assignment->id());
      $canceledSubscription = FALSE;

      if (!$this->isSubscriptionActiveForStorage($subscription)) {
        $canceledSubscription = TRUE;
      }
      else {
        $item = $this->resolveSubscriptionItem($client, $subscription, $subscriptionItemId, $assignment, $priceId);

        if ($item === NULL) {
          $hasExternalItems = $this->subscriptionHasExternalItems($subscription, NULL);
          if ($managedByStorageManager && empty($subscriptionAssignmentIds) && !$hasExternalItems) {
            $client->subscriptions->cancel($subscriptionId, []);
            $canceledSubscription = TRUE;
          }
          else {
            $this->logger->warning('Unable to locate Stripe subscription item for assignment @id in subscription @subscription.', [
              '@id' => $assignment->id(),
              '@subscription' => $subscriptionId,
            ]);
            throw new \RuntimeException('Unable to find the Stripe subscription item for this assignment.');
          }
        }
        else {
          $remainingItemAssignments = $this->collectAssignmentIdsForItem($subscription, $item, (int) $assignment->id());

          if (empty($remainingItemAssignments)) {
            $hasExternalItems = $this->subscriptionHasExternalItems($subscription, $item->id ?? NULL);
            if ($managedByStorageManager && empty($subscriptionAssignmentIds) && !$hasExternalItems) {
              $client->subscriptions->cancel($subscriptionId, []);
              $canceledSubscription = TRUE;
            }
            else {
              $client->subscriptionItems->delete($item->id, []);
            }
          }
          else {
            $this->updateSubscriptionItemMetadata($client, $item->id, $remainingItemAssignments);
          }
        }

        if (!$canceledSubscription) {
          $this->updateSubscriptionAssignmentsMetadata($client, $subscriptionId, $subscriptionAssignmentIds);
        }
      }

      if ($this->setFieldValue($assignment, 'field_storage_stripe_sub_id', $canceledSubscription ? '' : $subscriptionId)) {
        $needsSave = TRUE;
      }
      if ($this->setFieldValue($assignment, 'field_storage_stripe_item_id', '')) {
        $needsSave = TRUE;
      }
      $statusValue = $canceledSubscription ? 'canceled' : ($subscription->status ?? '');
      if ($this->setFieldValue($assignment, 'field_storage_stripe_status', $statusValue)) {
        $needsSave = TRUE;
      }

      if ($this->clearManualReview($assignment)) {
        $needsSave = TRUE;
      }

      if ($needsSave) {
        $assignment->save();
      }
    }
    catch (ApiErrorException $e) {
      $this->flagManualReviewRequired($assignment, $e->getMessage());
      $this->logger->error('Stripe API error while releasing storage assignment @id: @message', [
        '@id' => $assignment->id(),
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException($e->getMessage(), 0, $e);
    }
    catch (\Throwable $e) {
      $this->flagManualReviewRequired($assignment, $e->getMessage());
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
          return $type_price;
        }
      }
    }

    $default = trim((string) $this->configFactory->get('storage_manager.settings')->get('stripe.default_price_id'));
    return $default !== '' ? $default : '';
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
   * Build metadata for subscription line items.
   */
  protected function buildMetadata(EckEntityInterface $assignment): array {
    $unit = $assignment->get('field_storage_unit')->entity;
    $unitLabel = $unit?->get('field_storage_unit_id')->value ?? $unit?->label() ?? '';
    $typeLabel = $unit?->get('field_storage_type')->entity?->label() ?? '';
    $user = $assignment->get('field_storage_user')->entity;
    $memberName = $user instanceof UserInterface ? $user->getDisplayName() : '';
    $memberEmail = $user instanceof UserInterface ? $user->getEmail() : '';
    $priceSnapshot = $assignment->hasField('field_storage_price_snapshot')
      ? trim((string) ($assignment->get('field_storage_price_snapshot')->value ?? ''))
      : '';
    $reference = $this->buildAssignmentReference($unitLabel, $typeLabel);

    return array_filter([
      'storage_assignment_id' => (string) $assignment->id(),
      'storage_unit' => $unitLabel,
      'storage_type' => $typeLabel,
      'storage_reference' => $reference,
      'storage_member_name' => $memberName,
      'storage_member_email' => $memberEmail,
      'storage_price_snapshot' => $priceSnapshot !== '' ? $priceSnapshot : NULL,
    ]);
  }

  /**
   * Attempt to locate an existing storage-managed subscription for this user.
   */
  protected function findStorageSubscriptionId(EckEntityInterface $assignment): ?string {
    $user = $assignment->get('field_storage_user')->entity;
    if (!$user instanceof UserInterface) {
      return NULL;
    }

    if (!$this->assignmentFieldExists('field_storage_stripe_sub_id')) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('storage_assignment');
    $query = $storage->getQuery()
      ->condition('id', $assignment->id(), '<>')
      ->condition('field_storage_user', $user->id())
      ->condition('field_storage_stripe_sub_id', '', '<>')
      ->accessCheck(FALSE)
      ->sort('id', 'DESC')
      ->range(0, 5);

    $ids = $query->execute();
    if (!$ids) {
      return NULL;
    }

    /** @var \Drupal\eck\EckEntityInterface[] $candidates */
    $candidates = $storage->loadMultiple($ids);
    foreach ($candidates as $candidate) {
      if (!$candidate->hasField('field_storage_stripe_sub_id')) {
        continue;
      }
      $subId = trim((string) ($candidate->get('field_storage_stripe_sub_id')->value ?? ''));
      if ($subId === '') {
        continue;
      }
      $status = $candidate->hasField('field_storage_stripe_status')
        ? trim((string) ($candidate->get('field_storage_stripe_status')->value ?? ''))
        : '';
      if (strtolower($status) === 'canceled') {
        continue;
      }
      return $subId;
    }

    return NULL;
  }

  /**
   * Ensure we have a Stripe subscription item for this assignment.
   *
   * @param string $priceId
   *   The Stripe price ID currently expected for the assignment.
   */
  protected function resolveSubscriptionItem(StripeClient $client, Subscription $subscription, string $itemId, EckEntityInterface $assignment, string $priceId): ?SubscriptionItem {
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

    $assignmentId = (int) $assignment->id();

    foreach ($subscription->items->data as $subscriptionItem) {
      $metadataIds = $this->parseAssignmentIds($subscriptionItem->metadata['storage_assignment_ids'] ?? '');
      if (in_array($assignmentId, $metadataIds, TRUE)) {
        return $subscriptionItem;
      }
      if (($subscriptionItem->metadata['storage_assignment_id'] ?? '') === (string) $assignmentId) {
        return $subscriptionItem;
      }
    }

    if ($priceId !== '') {
      foreach ($subscription->items->data as $subscriptionItem) {
        if (($subscriptionItem->metadata['storage_manager_assignment'] ?? '') === '1'
          && (($subscriptionItem->price->id ?? '') === $priceId)) {
          return $subscriptionItem;
        }
      }
      foreach ($subscription->items->data as $subscriptionItem) {
        if (($subscriptionItem->price->id ?? '') === $priceId) {
          return $subscriptionItem;
        }
      }
    }

    return NULL;
  }

  /**
   * Collect assignment IDs that reference a specific subscription.
   */
  protected function collectAssignmentIdsForSubscription(Subscription $subscription, ?int $excludeAssignmentId = NULL): array {
    $metadataIds = $this->parseAssignmentIds($subscription->metadata['storage_assignment_ids'] ?? '');
    if (!empty($metadataIds)) {
      $metadataIds = array_filter($metadataIds, static function (int $id) use ($excludeAssignmentId) {
        return $excludeAssignmentId === NULL || $id !== $excludeAssignmentId;
      });
      return array_values($metadataIds);
    }

    $subscriptionId = $subscription->id;
    if ($subscriptionId === NULL || !$this->assignmentFieldExists('field_storage_stripe_sub_id')) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('storage_assignment');
    $query = $storage->getQuery()
      ->condition('field_storage_stripe_sub_id', $subscriptionId)
      ->accessCheck(FALSE);

    if ($excludeAssignmentId !== NULL) {
      $query->condition('id', $excludeAssignmentId, '<>');
    }

    $ids = $query->execute();
    $assignmentIds = array_map('intval', array_values($ids));
    sort($assignmentIds);
    return $assignmentIds;
  }

  /**
   * Collect assignment IDs that share a subscription item.
   */
  protected function collectAssignmentIdsForItem(Subscription $subscription, SubscriptionItem $item, ?int $excludeAssignmentId = NULL): array {
    $metadataIds = $this->parseAssignmentIds($item->metadata['storage_assignment_ids'] ?? '');
    if (!empty($metadataIds)) {
      $metadataIds = array_filter($metadataIds, static function (int $id) use ($excludeAssignmentId) {
        return $excludeAssignmentId === NULL || $id !== $excludeAssignmentId;
      });
      return array_values($metadataIds);
    }

    $subscriptionId = $subscription->id;
    $itemId = $item->id;
    if ($subscriptionId === NULL || $itemId === NULL) {
      return [];
    }

    if (
      !$this->assignmentFieldExists('field_storage_stripe_sub_id') ||
      !$this->assignmentFieldExists('field_storage_stripe_item_id')
    ) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('storage_assignment');
    $query = $storage->getQuery()
      ->condition('field_storage_stripe_sub_id', $subscriptionId)
      ->condition('field_storage_stripe_item_id', $itemId)
      ->accessCheck(FALSE);

    if ($excludeAssignmentId !== NULL) {
      $query->condition('id', $excludeAssignmentId, '<>');
    }

    $ids = $query->execute();
    $assignmentIds = array_map('intval', array_values($ids));
    sort($assignmentIds);
    return $assignmentIds;
  }

  /**
   * Determine if a subscription includes items not managed by storage manager.
   */
  protected function subscriptionHasExternalItems(Subscription $subscription, ?string $skipItemId = NULL): bool {
    foreach ($subscription->items->data as $subscriptionItem) {
      if ($skipItemId !== NULL && $subscriptionItem->id === $skipItemId) {
        continue;
      }
      if (($subscriptionItem->metadata['storage_manager_assignment'] ?? '') !== '1') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Update Stripe subscription metadata to reflect linked assignments.
   */
  protected function updateSubscriptionAssignmentsMetadata(StripeClient $client, string $subscriptionId, array $assignmentIds): void {
    sort($assignmentIds);
    $metadata = $this->buildSubscriptionMetadataPayload($assignmentIds);

    $client->subscriptions->update($subscriptionId, ['metadata' => $metadata]);
  }

  /**
   * Update Stripe subscription item metadata and quantity.
   */
  protected function updateSubscriptionItemMetadata(StripeClient $client, string $itemId, array $assignmentIds, ?string $priceId = NULL): SubscriptionItem {
    sort($assignmentIds);
    $reference = $this->buildItemReference($assignmentIds);
    $metadata = [
      'storage_manager_assignment' => '1',
      'storage_assignment_ids' => $assignmentIds ? implode(',', $assignmentIds) : '',
      'storage_assignment_id' => $assignmentIds ? (string) reset($assignmentIds) : '',
      'storage_reference' => $reference,
    ];

    $params = [
      'metadata' => $metadata,
      'quantity' => max(count($assignmentIds), 1),
    ];

    if ($priceId !== NULL && $priceId !== '') {
      $params['price'] = $priceId;
    }

    return $client->subscriptionItems->update($itemId, $params);
  }

  /**
   * Build aggregate metadata for a subscription.
   */
  protected function buildSubscriptionMetadataPayload(array $assignmentIds): array {
    sort($assignmentIds);
    $metadata = [
      'storage_manager_managed' => '1',
      'storage_manager' => '1',
      'storage_assignment_ids' => $assignmentIds ? implode(',', $assignmentIds) : '',
    ];

    if (!$assignmentIds) {
      return $metadata;
    }

    $storage = $this->entityTypeManager->getStorage('storage_assignment');
    $assignments = $storage->loadMultiple($assignmentIds);
    $unitLabels = [];
    $memberNames = [];
    $references = [];

    foreach ($assignments as $assignment) {
      $unit = $assignment->get('field_storage_unit')->entity;
      $unitLabel = $unit?->get('field_storage_unit_id')->value ?? $unit?->label() ?? '';
      if ($unitLabel !== '') {
        $unitLabels[] = $unitLabel;
      }
      $typeLabel = $unit?->get('field_storage_type')->entity?->label() ?? '';
      $references[] = $this->buildAssignmentReference($unitLabel, $typeLabel);

      $user = $assignment->get('field_storage_user')->entity;
      if ($user instanceof UserInterface) {
        $memberNames[] = $user->getDisplayName() ?: $user->getEmail();
      }
    }

    if ($unitLabels) {
      $metadata['storage_units'] = implode(', ', array_unique($unitLabels));
    }
    if ($memberNames) {
      $metadata['storage_members'] = implode(', ', array_unique($memberNames));
    }
    if ($references) {
      $metadata['storage_reference'] = implode(' | ', array_filter($references));
    }

    return array_filter($metadata);
  }

  /**
   * Build a concise reference string for an assignment.
   */
  protected function buildAssignmentReference(?string $unitLabel, ?string $typeLabel): string {
    $parts = [];
    if ($unitLabel) {
      $parts[] = $unitLabel;
    }
    if ($typeLabel) {
      $parts[] = $typeLabel;
    }
    return implode(' - ', array_filter($parts));
  }

  /**
   * Build item-level reference string for metadata.
   */
  protected function buildItemReference(array $assignmentIds): string {
    if (!$assignmentIds) {
      return '';
    }
    $storage = $this->entityTypeManager->getStorage('storage_assignment');
    $assignment = $storage->load(reset($assignmentIds));
    if (!$assignment) {
      return '';
    }
    $unit = $assignment->get('field_storage_unit')->entity;
    $unitLabel = $unit?->get('field_storage_unit_id')->value ?? $unit?->label() ?? '';
    $typeLabel = $unit?->get('field_storage_type')->entity?->label() ?? '';
    return $this->buildAssignmentReference($unitLabel, $typeLabel);
  }

  /**
   * Convert a metadata string of IDs into an integer array.
   */
  protected function parseAssignmentIds($value): array {
    if (!is_string($value)) {
      return [];
    }
    $value = trim($value);
    if ($value === '') {
      return [];
    }
    $parts = preg_split('/\s*,\s*/', $value);
    $ids = [];
    foreach ($parts as $part) {
      if ($part === '') {
        continue;
      }
      $ids[] = (int) $part;
    }
    sort($ids);
    return $ids;
  }

  /**
   * Determine whether a subscription is reusable for storage billing.
   */
  protected function isSubscriptionActiveForStorage(Subscription $subscription): bool {
    $status = strtolower((string) ($subscription->status ?? ''));
    if (in_array($status, ['canceled', 'incomplete_expired'], TRUE)) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Check whether a storage assignment field exists before querying.
   */
  protected function assignmentFieldExists(string $fieldName): bool {
    static $cache = NULL;

    if ($cache === NULL) {
      $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('storage_assignment', 'storage_assignment');
      $cache = array_fill_keys(array_keys($definitions), TRUE);
    }

    return isset($cache[$fieldName]);
  }

  /**
   * Flag an assignment for manual Stripe review and notify staff.
   */
  protected function flagManualReviewRequired(EckEntityInterface $assignment, string $reason): void {
    if (!$assignment->hasField('field_stripe_manual_review')) {
      return;
    }

    $wasFlagged = (bool) ($assignment->get('field_stripe_manual_review')->value ?? FALSE);
    $needsSave = FALSE;

    if ($this->setFieldValue($assignment, 'field_stripe_manual_review', '1')) {
      $needsSave = TRUE;
    }

    if ($assignment->hasField('field_stripe_manual_note')) {
      $note = trim($reason) !== '' ? trim($reason) : 'Stripe sync failed; manual follow-up required.';
      $note = Unicode::truncate($note, 1000, TRUE, TRUE);
      if ($this->setFieldValue($assignment, 'field_stripe_manual_note', $note)) {
        $needsSave = TRUE;
      }
    }

    if ($needsSave) {
      try {
        $assignment->save();
      }
      catch (\Throwable $saveError) {
        $this->logger->error('Failed to persist manual Stripe review flag for assignment @id: @message', [
          '@id' => $assignment->id(),
          '@message' => $saveError->getMessage(),
        ]);
      }
    }

    if (!$wasFlagged) {
      try {
        $this->notificationManager->notifyManualStripeAction($assignment, $reason);
      }
      catch (\Throwable $notifyError) {
        $this->logger->error('Failed to send manual Stripe review notification for assignment @id: @message', [
          '@id' => $assignment->id(),
          '@message' => $notifyError->getMessage(),
        ]);
      }
    }
  }

  /**
   * Clear any manual Stripe review flag and optionally record a note.
   */
  public function clearManualReview(EckEntityInterface $assignment, ?string $note = ''): bool {
    if (!$assignment->hasField('field_stripe_manual_review')) {
      return FALSE;
    }

    $changed = FALSE;
    if ($this->setFieldValue($assignment, 'field_stripe_manual_review', '0')) {
      $changed = TRUE;
    }

    if ($assignment->hasField('field_stripe_manual_note') && $note !== NULL) {
      $noteValue = $note === '' ? '' : Unicode::truncate($note, 1000, TRUE, TRUE);
      if ($this->setFieldValue($assignment, 'field_stripe_manual_note', $noteValue)) {
        $changed = TRUE;
      }
    }

    return $changed;
  }

  /**
   * Set a field value only when the new value differs from the current value.
   */
  protected function setFieldValue(EckEntityInterface $entity, string $fieldName, $value): bool {
    if (!$entity->hasField($fieldName)) {
      return FALSE;
    }

    $field = $entity->get($fieldName);

    if (is_array($value)) {
      $current = $field->getValue();
      if ($current === $value) {
        return FALSE;
      }
      $entity->set($fieldName, $value);
      return TRUE;
    }

    $currentValue = $field->isEmpty() ? '' : $field->getString();
    $newValue = $value === NULL ? '' : (string) $value;

    if ($field->isEmpty() && $newValue === '') {
      return FALSE;
    }

    if (!$field->isEmpty() && $currentValue === $newValue) {
      return FALSE;
    }

    $entity->set($fieldName, $newValue);
    return TRUE;
  }

}

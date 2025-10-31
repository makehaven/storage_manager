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

    if ($assignment->isTranslatable()) {
      $assignment = $assignment->getUntranslated();
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
          'metadata' => [
            'storage_manager_managed' => '1',
            'storage_assignment_ids' => (string) $assignment->id(),
          ],
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

      if ($needsSave) {
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
          if ($managedByStorageManager && empty($subscriptionAssignmentIds)) {
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
            if ($managedByStorageManager && empty($subscriptionAssignmentIds)) {
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
      if ($this->setFieldValue($assignment, 'field_storage_stripe_status', 'canceled')) {
        $needsSave = TRUE;
      }

      if ($needsSave) {
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

    return array_filter([
      'storage_assignment_id' => (string) $assignment->id(),
      'storage_unit' => $unitLabel,
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
   * Update Stripe subscription metadata to reflect linked assignments.
   */
  protected function updateSubscriptionAssignmentsMetadata(StripeClient $client, string $subscriptionId, array $assignmentIds): void {
    sort($assignmentIds);
    $metadata = [
      'storage_manager_managed' => '1',
      'storage_assignment_ids' => $assignmentIds ? implode(',', $assignmentIds) : '',
    ];

    $client->subscriptions->update($subscriptionId, ['metadata' => $metadata]);
  }

  /**
   * Update Stripe subscription item metadata and quantity.
   */
  protected function updateSubscriptionItemMetadata(StripeClient $client, string $itemId, array $assignmentIds, ?string $priceId = NULL): SubscriptionItem {
    sort($assignmentIds);
    $metadata = [
      'storage_manager_assignment' => '1',
      'storage_assignment_ids' => $assignmentIds ? implode(',', $assignmentIds) : '',
      'storage_assignment_id' => $assignmentIds ? (string) reset($assignmentIds) : '',
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

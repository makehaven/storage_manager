<?php

namespace Drupal\storage_manager\Form;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\storage_manager\Service\StripeAssignmentManager;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirm form to link existing Stripe subscriptions to assignments.
 */
class SyncCustomerSubscriptionsForm extends ConfirmFormBase {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected StripeAssignmentManager $stripeAssignmentManager;
  /**
   * Optional Stripe helper service from mh_stripe.
   *
   * @var object|null
   */
  protected $stripeHelper;
  protected LoggerInterface $logger;
  protected CacheTagsInvalidatorInterface $cacheTagsInvalidator;
  protected ?UserInterface $targetUser = NULL;
  protected array $priceLabelCache = [];

  public function __construct(EntityTypeManagerInterface $entity_type_manager, StripeAssignmentManager $stripe_assignment_manager, $stripe_helper, LoggerInterface $logger, CacheTagsInvalidatorInterface $cache_tags_invalidator) {
    $this->entityTypeManager = $entity_type_manager;
    $this->stripeAssignmentManager = $stripe_assignment_manager;
    $this->stripeHelper = $stripe_helper;
    $this->logger = $logger;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('storage_manager.stripe_assignment_manager'),
      $container->has('mh_stripe.helper') ? $container->get('mh_stripe.helper') : NULL,
      $container->get('logger.channel.storage_manager'),
      $container->get('cache_tags.invalidator')
    );
  }

  public function getFormId(): string {
    return 'storage_manager_sync_customer_subscriptions';
  }

  public function getQuestion(): string {
    $name = $this->targetUser?->getDisplayName() ?? $this->t('this member');
    return $this->t('Link existing Stripe subscriptions for @name?', ['@name' => $name]);
  }

  public function getDescription(): string {
    return $this->t('Review the detected Stripe subscription items below. Only the listed storage assignments will be updated.');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('storage_manager.dashboard');
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?UserInterface $user = NULL): array {
    $this->targetUser = $user ?? $this->targetUser;
    if (!$this->targetUser instanceof UserInterface) {
      throw new \InvalidArgumentException('User context is required.');
    }

    if (!$this->stripeAssignmentManager->isEnabled() || $this->stripeHelper === NULL) {
      $this->messenger()->addError($this->t('Stripe integration is not enabled.'));
      return $form;
    }

    $customerId = $this->stripeAssignmentManager->getStoredCustomerId($this->targetUser);
    if ($customerId === '') {
      $this->messenger()->addError($this->t('This member does not have a Stripe customer ID on file.'));
      return $form;
    }

    $assignment_storage = $this->entityTypeManager->getStorage('storage_assignment');
    $assignment_ids = $assignment_storage->getQuery()
      ->condition('field_storage_user', $this->targetUser->id())
      ->condition('field_storage_assignment_status', 'active')
      ->accessCheck(FALSE)
      ->execute();
    if (!$assignment_ids) {
      $this->messenger()->addWarning($this->t('No active storage assignments found for this member.'));
      return $form;
    }

    $assignments = $assignment_storage->loadMultiple($assignment_ids);
    $subscriptions = $this->stripeAssignmentManager->loadCustomerSubscriptions($customerId);
    $assignmentSelections = $this->buildAssignmentSelections($assignments, $subscriptions);

    if ($assignmentSelections) {
      $form['assignments'] = [
        '#type' => 'details',
        '#title' => $this->t('Assignments awaiting Stripe links'),
        '#open' => TRUE,
        '#tree' => TRUE,
      ];

      foreach ($assignmentSelections as $selection) {
        $form['assignments'][$selection['assignment_id']] = [
          '#type' => 'details',
          '#title' => $selection['assignment_label'],
          '#open' => TRUE,
        ];

        $snapshotDisplay = $selection['snapshot_price'] !== ''
          ? '$' . number_format((float) $selection['snapshot_price'], 2)
          : (string) $this->t('not recorded');
        $startDisplay = $selection['start_date']
          ? date('Y-m-d', strtotime($selection['start_date']))
          : (string) $this->t('not recorded');

        $summary_items = [
          $this->t('Expected price ID: <code>@price</code> (@label)', [
            '@price' => $selection['price_id'] ?: $this->t('not set'),
            '@label' => $selection['price_label'] ?: $this->t('Unknown type'),
          ]),
          $this->t('Current link: @link', ['@link' => $selection['current_label']]),
          $this->t('Price snapshot: @snapshot', ['@snapshot' => $snapshotDisplay]),
          $this->t('Start date: @start', ['@start' => $startDisplay]),
        ];
        if ($selection['release_photo']) {
          $summary_items[] = Markup::create('Latest release photo: ' . $selection['release_photo']);
        }

        $form['assignments'][$selection['assignment_id']]['meta'] = [
          '#theme' => 'item_list',
          '#items' => $summary_items,
        ];

        $form['assignments'][$selection['assignment_id']]['selection'] = [
          '#type' => 'radios',
          '#title' => $this->t('Link to subscription item'),
          '#options' => $selection['options'],
          '#default_value' => 'skip',
        ];
      }

      $form_state->set('pending_assignments', array_column($assignmentSelections, NULL, 'assignment_id'));
      $form = parent::buildForm($form, $form_state);
    }
    else {
      $form['summary'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['storage-sync-summary']],
        'message' => [
          '#markup' => $this->t('All storage assignments already have Stripe links or no matching subscription items were found. Review the detected subscriptions below for reference.'),
        ],
      ];
      $form['back'] = [
        '#type' => 'link',
        '#title' => $this->t('Back to storage dashboard'),
        '#url' => $this->getCancelUrl(),
        '#attributes' => ['class' => ['button']],
      ];
    }

    $form['subscriptions'] = [
      '#type' => 'details',
      '#title' => $this->t('Detected Stripe subscriptions'),
      '#open' => TRUE,
      'table' => $this->buildSubscriptionTable($subscriptions),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $pending = $form_state->get('pending_assignments') ?? [];
    if (!$pending) {
      $this->messenger()->addStatus($this->t('No changes were selected.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    $assignment_storage = $this->entityTypeManager->getStorage('storage_assignment');
    $success = 0;
    $failures = 0;
    $usedItems = [];

    foreach ($pending as $assignment_id => $meta) {
      $selection = $form_state->getValue(['assignments', $assignment_id, 'selection']);
      if ($selection === NULL) {
        continue;
      }
      if ($selection === '' || $selection === 'skip') {
        continue;
      }
      [$subscription_id, $item_id] = explode('::', $selection);
      if (isset($usedItems[$item_id])) {
        $this->messenger()->addWarning($this->t('Subscription item @item was selected more than once; skipping duplicate link.', ['@item' => $item_id]));
        continue;
      }

      $assignment = $assignment_storage->load($assignment_id);
      if (!$assignment) {
        $failures++;
        continue;
      }

      try {
        $this->stripeAssignmentManager->linkAssignmentToSubscriptionItem($assignment, $subscription_id, $item_id);
        $success++;
        $usedItems[$item_id] = TRUE;
      }
      catch (\Throwable $e) {
        $failures++;
        $this->logger->error('Failed to link assignment @id to Stripe subscription @sub: @message', [
          '@id' => $assignment->id(),
          '@sub' => $subscription_id,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    if ($success) {
      $this->messenger()->addStatus($this->t('Linked @count storage assignment(s) to Stripe.', ['@count' => $success]));
      $this->cacheTagsInvalidator->invalidateTags(['storage_assignment_list']);
    }
    if ($failures) {
      $this->messenger()->addError($this->t('@count storage assignment(s) could not be linked. Check the logs for details.', ['@count' => $failures]));
    }
    if (!$success && !$failures) {
      $this->messenger()->addStatus($this->t('No changes were selected.'));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  protected function buildAssignmentSelections(array $assignments, array $subscriptions): array {
    $selections = [];
    $autoUsedItems = [];

    foreach ($assignments as $assignment) {
      $priceId = $this->stripeAssignmentManager->getAssignmentPriceId($assignment);
      if ($priceId === '') {
        continue;
      }
      $currentSub = $assignment->hasField('field_storage_stripe_sub_id')
        ? trim((string) ($assignment->get('field_storage_stripe_sub_id')->value ?? ''))
        : '';
      $currentItem = $assignment->hasField('field_storage_stripe_item_id')
        ? trim((string) ($assignment->get('field_storage_stripe_item_id')->value ?? ''))
        : '';
      if ($currentSub !== '' && $currentItem !== '') {
        continue;
      }

      $options = $this->buildItemOptions($subscriptions, $priceId);
      if (count($options) <= 1) {
        continue;
      }

      $match = $this->findMatchingItem($assignment, $priceId, $subscriptions, $autoUsedItems);
      $defaultOption = $match ? $match['subscription_id'] . '::' . $match['item_id'] : '';
      if ($match) {
        $autoUsedItems[] = $match['item_id'];
      }

      $selections[] = [
        'assignment_id' => $assignment->id(),
        'assignment_label' => $this->buildAssignmentLabel($assignment),
        'price_id' => $priceId,
        'price_label' => $this->getPriceLabel($priceId),
        'current_label' => $currentSub ? $this->t('@sub / @item', ['@sub' => $currentSub, '@item' => $currentItem ?: $this->t('N/A')]) : $this->t('Not linked'),
        'snapshot_price' => $assignment->hasField('field_storage_price_snapshot')
          ? ($assignment->get('field_storage_price_snapshot')->value ?? '')
          : '',
        'start_date' => $assignment->hasField('field_storage_start_date')
          ? ($assignment->get('field_storage_start_date')->value ?? '')
          : '',
        'release_photo' => $this->buildReleasePhotoLink($assignment),
        'options' => $options,
        'default_option' => $defaultOption,
      ];
    }

    return $selections;
  }

  protected function buildItemOptions(array $subscriptions, string $expectedPriceId): array {
    $options = [];
    foreach ($subscriptions as $subscription) {
      foreach ($subscription->items->data as $item) {
        if (empty($item->id)) {
          continue;
        }
        $priceId = $item->price->id ?? '';
        if ($priceId === '' || $priceId !== $expectedPriceId) {
          continue;
        }
        $metadataIds = trim((string) ($item->metadata['storage_assignment_ids'] ?? ''));
        if ($metadataIds !== '') {
          // Already linked to a storage assignment.
          continue;
        }
        $knownLabel = $this->getPriceLabel($priceId);
        $productName = $knownLabel ?: ($this->resolveProductName($item->price) ?? $this->t('Unknown product'));
        $optionLabel = $this->t('@product – @price (qty: @qty) in subscription @sub (@status)', [
          '@product' => $productName,
          '@price' => $priceId ?: $this->t('Unknown price'),
          '@qty' => $item->quantity ?? 1,
          '@sub' => $subscription->id ?? $this->t('unknown'),
          '@status' => ucfirst($subscription->status ?? 'unknown'),
        ]);
        $options[$subscription->id . '::' . $item->id] = $optionLabel;
      }
    }
    if ($options) {
      $options = ['skip' => $this->t('Leave unlinked (no change)')] + $options;
    }
    return $options;
  }

  protected function findMatchingItem($assignment, string $priceId, array $subscriptions, array $usedItems): ?array {
    foreach ($subscriptions as $subscription) {
      foreach ($subscription->items->data as $item) {
        if (($item->id ?? '') === '' || in_array($item->id, $usedItems, TRUE)) {
          continue;
        }
        $itemPriceId = $item->price->id ?? '';
        if ($itemPriceId !== $priceId) {
          continue;
        }
        return [
          'subscription_id' => $subscription->id,
          'subscription_status' => $subscription->status ?? 'unknown',
          'item_id' => $item->id,
        ];
      }
    }
    return NULL;
  }

  protected function buildAssignmentLabel($assignment): string {
    $unit = $assignment->get('field_storage_unit')->entity;
    $unitLabel = $unit?->get('field_storage_unit_id')->value ?? $unit?->label() ?? $this->t('Unknown unit');
    $type = $unit?->get('field_storage_type')->entity?->label() ?? $this->t('Unknown type');
    return $this->t('Assignment #@id – @unit (@type)', [
      '@id' => $assignment->id(),
      '@unit' => $unitLabel,
      '@type' => $type,
    ]);
  }

protected function buildSubscriptionTable(array $subscriptions): array {
    if (!$subscriptions) {
      return [
        '#markup' => $this->t('No Stripe subscriptions found for this customer.'),
      ];
    }

    $rows = [];
    foreach ($subscriptions as $subscription) {
      if (empty($subscription->items->data)) {
        $rows[] = [
          $subscription->id ?? $this->t('Unknown'),
          ucfirst($subscription->status ?? 'unknown'),
          $this->t('No line items'),
        ];
        continue;
      }
      foreach ($subscription->items->data as $item) {
        $priceId = $item->price->id ?? '';
        $productName = $this->getPriceLabel($priceId)
          ?: ($this->resolveProductName($item->price) ?? $this->t('Unknown product'));
        $rows[] = [
          $subscription->id ?? $this->t('Unknown'),
          ucfirst($subscription->status ?? 'unknown'),
          $this->t('@product – @price (quantity: @quantity)', [
            '@product' => $productName,
            '@price' => $priceId ?: $this->t('Unknown price'),
            '@quantity' => $item->quantity ?? 1,
          ]),
        ];
      }
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Subscription'),
        $this->t('Status'),
        $this->t('Items'),
      ],
      '#rows' => $rows,
    ];
  }

  protected function getPriceLabel(string $priceId): ?string {
    if ($priceId === '') {
      return NULL;
    }
    if (array_key_exists($priceId, $this->priceLabelCache)) {
      return $this->priceLabelCache[$priceId];
    }

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $term_storage->getQuery()
      ->condition('vid', 'storage_type')
      ->condition('field_stripe_price_id', $priceId)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    if ($ids) {
      $term = $term_storage->load(reset($ids));
      $label = $term?->label();
      return $this->priceLabelCache[$priceId] = $label ?: NULL;
    }
    return $this->priceLabelCache[$priceId] = NULL;
  }

  protected function resolveProductName($price): ?string {
    if (!is_object($price)) {
      return NULL;
    }
    if (isset($price->product) && is_object($price->product) && isset($price->product->name) && $price->product->name !== '') {
      return $price->product->name;
    }
    if (isset($price->nickname) && $price->nickname !== '') {
      return $price->nickname;
    }
    return NULL;
  }

  protected function buildReleasePhotoLink($assignment): ?string {
    if (!$assignment->hasField('field_release_photo') || $assignment->get('field_release_photo')->isEmpty()) {
      return NULL;
    }
    $item = $assignment->get('field_release_photo')->first();
    if (!$item || !$item->entity) {
      return NULL;
    }
    $file = $item->entity;
    $urlString = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
    $url = Url::fromUri($urlString, [
      'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
    ]);
    return Link::fromTextAndUrl($this->t('View photo'), $url)->toString();
  }

}

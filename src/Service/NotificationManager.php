<?php

namespace Drupal\storage_manager\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\eck\EckEntityInterface;
use Drupal\storage_manager\Service\ViolationManager;
use Drupal\user\UserInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

class NotificationManager {
  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
    private readonly ViolationManager $violationManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Sends a notification for the given event if enabled.
   */
  public function sendEvent(string $event, array $context = []): void {
    $config = $this->configFactory->get('storage_manager.settings');
    $enabled = $config->get('notifications.enabled_events') ?? [];
    if (!in_array($event, $enabled, TRUE)) {
      return;
    }

    $templates = $config->get('notifications.templates') ?? [];
    if (empty($templates[$event]['subject']) || empty($templates[$event]['body'])) {
      $this->logger->warning('Storage Manager notification "@event" skipped because no template is configured.', ['@event' => $event]);
      return;
    }
    $admin_templates = $config->get('notifications.templates_admin') ?? [];
    $admin_defaults = $this->getDefaultAdminTemplates();
    $admin_template = $admin_templates[$event] ?? ($admin_defaults[$event] ?? ['subject' => '', 'body' => '']);

    $assignment = $context['assignment'] ?? NULL;
    if (!$assignment instanceof EckEntityInterface) {
      $this->logger->warning('Storage Manager notification "@event" missing assignment context.', ['@event' => $event]);
      return;
    }

    $user = $context['user'] ?? $this->resolveUser($assignment);
    $unit = $context['unit'] ?? $this->resolveUnit($assignment);

    $replacements = $this->buildReplacements($assignment, $unit, $user, $context);

    $subject = $this->replaceTokens($templates[$event]['subject'], $replacements);
    $body = $this->replaceTokens($templates[$event]['body'], $replacements);
    $admin_subject = trim((string) ($admin_template['subject'] ?? '')) !== ''
      ? $this->replaceTokens($admin_template['subject'], $replacements)
      : $subject;
    $admin_body = trim((string) ($admin_template['body'] ?? '')) !== ''
      ? $this->replaceTokens($admin_template['body'], $replacements)
      : $body;

    $langcode = $user instanceof UserInterface
      ? ($user->getPreferredLangcode() ?: $this->languageManager->getDefaultLanguage()->getId())
      : $this->languageManager->getDefaultLanguage()->getId();

    if ($user instanceof UserInterface && $user->getEmail()) {
      $this->deliver($event, $user->getEmail(), $langcode, $subject, $body);
    }
    else {
      $this->logger->notice('Storage Manager notification "@event" skipped because no recipient email exists.', ['@event' => $event]);
    }

    $recipients = $config->get('notifications.recipients') ?: '';
    if ($recipients) {
      $addresses = array_filter(array_map('trim', explode(',', $recipients)));
      foreach ($addresses as $address) {
        $this->deliver($event, $address, $langcode, $admin_subject, $admin_body);
      }
    }
  }

  /**
   * Notify configured administrators that Stripe needs manual intervention.
   */
  public function notifyManualStripeAction(EckEntityInterface $assignment, string $reason): void {
    $config = $this->configFactory->get('storage_manager.settings');
    $recipients = $config->get('notifications.recipients') ?: '';
    if (!$recipients) {
      return;
    }
    $addresses = array_filter(array_map('trim', explode(',', $recipients)));
    if (empty($addresses)) {
      return;
    }

    $unit = $this->resolveUnit($assignment);
    $user = $this->resolveUser($assignment);
    $unitLabel = $unit?->get('field_storage_unit_id')->value ?? $unit?->label() ?? $this->t('Unknown unit');
    $memberName = $user?->getDisplayName() ?? $this->t('Unknown member');
    $memberEmail = $user?->getEmail() ?? $this->t('unknown');
    $reasonText = trim($reason) !== '' ? $reason : $this->t('Unknown Stripe error.');
    $assignmentUrl = Url::fromRoute('storage_manager.assignment_edit', ['storage_assignment' => $assignment->id()], ['absolute' => TRUE])->toString();

    $subject = $this->t('Manual Stripe action required for storage assignment @id', ['@id' => $assignment->id()]);
    $body = $this->t("Automatic Stripe synchronization failed for storage assignment @id.\n\nUnit: @unit\nMember: @member (@email)\nReason: @reason\n\nReview the assignment: @url", [
      '@id' => $assignment->id(),
      '@unit' => $unitLabel,
      '@member' => $memberName,
      '@email' => $memberEmail,
      '@reason' => $reasonText,
      '@url' => $assignmentUrl,
    ]);

    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    foreach ($addresses as $address) {
      $this->deliver('manual_stripe_action', $address, $langcode, $subject, $body);
    }
  }

  protected function buildReplacements(EckEntityInterface $assignment, $unit, ?UserInterface $user, array $context): array {
    $type_term = $unit?->get('field_storage_type')->entity;
    $monthly_cost = $type_term?->get('field_monthly_price')->value;

    $start = $assignment->get('field_storage_start_date')->value;
    $end = $context['release_date'] ?? $assignment->get('field_storage_end_date')->value;

    $violation = $context['violation'] ?? NULL;
    if (!$violation) {
      $violations = $this->violationManager->loadViolations((int) $assignment->id());
      $violation = $violations[0] ?? NULL;
    }

    $violation_start = $violation?->get('field_storage_vi_start')->value;
    $violation_resolved = $context['violation_resolved'] ?? $violation?->get('field_storage_vi_resolved')->value;
    $violation_total = $context['violation_total'] ?? $violation?->get('field_storage_vi_total')->value;
    $violation_daily = $violation?->get('field_storage_vi_daily')->value ?? $this->violationManager->getDefaultDailyRate();

    $site_name = $this->configFactory->get('system.site')->get('name');

    $assignment_view_url = Url::fromRoute('entity.storage_assignment.canonical', ['storage_assignment' => $assignment->id()], ['absolute' => TRUE])->toString();
    $assignment_admin_url = Url::fromRoute('storage_manager.assignment_edit', ['storage_assignment' => $assignment->id()], ['absolute' => TRUE])->toString();
    $photo_links = $this->buildReleasePhotoLinks($assignment);
    $member_account_url = $user instanceof UserInterface
      ? Url::fromRoute('entity.user.canonical', ['user' => $user->id()], ['absolute' => TRUE])->toString()
      : '';

    $stripe_status = $this->getFieldString($assignment, 'field_storage_stripe_status');
    $stripe_subscription_id = $this->getFieldString($assignment, 'field_storage_stripe_sub_id');
    $stripe_customer_id = $this->getStripeCustomerId($assignment, $user);
    $manual_required = $assignment->hasField('field_stripe_manual_review')
      ? (bool) ($assignment->get('field_stripe_manual_review')->value ?? FALSE)
      : FALSE;
    $manual_note = $assignment->hasField('field_stripe_manual_note')
      ? trim((string) ($assignment->get('field_stripe_manual_note')->value ?? ''))
      : '';

    return [
      '[member_name]' => $user?->getDisplayName() ?? '',
      '[member_email]' => $user?->getEmail() ?? '',
      '[unit_id]' => $unit?->get('field_storage_unit_id')->value ?? '',
      '[storage_type]' => $type_term?->label() ?? '',
      '[monthly_cost]' => $this->formatMoney($monthly_cost),
      '[assignment_start]' => $this->formatDate($start),
      '[assignment_end]' => $this->formatDate($end),
      '[release_date]' => $this->formatDate($end),
      '[violation_start]' => $this->formatDate($violation_start),
      '[violation_resolved]' => $this->formatDate($violation_resolved),
      '[violation_daily_rate]' => $this->formatMoney($violation_daily),
      '[violation_total_due]' => $this->formatMoney($violation_total),
      '[site_name]' => $site_name ?: 'MakeHaven',
      '[generated_at]' => $this->formatDate($this->time->getRequestTime()),
      '[member_account_url]' => $member_account_url,
      '[assignment_view_url]' => $assignment_view_url,
      '[assignment_admin_url]' => $assignment_admin_url,
      '[release_photo_links]' => $photo_links ? implode("\n", $photo_links) : '',
      '[stripe_status]' => $stripe_status,
      '[stripe_subscription_id]' => $stripe_subscription_id,
      '[stripe_customer_id]' => $stripe_customer_id,
      '[stripe_manual_review_required]' => $manual_required ? $this->t('Yes') : $this->t('No'),
      '[stripe_manual_review_note]' => $manual_note,
    ];
  }

  protected function resolveUser(EckEntityInterface $assignment): ?UserInterface {
    $user = $assignment->get('field_storage_user')->entity;
    return $user instanceof UserInterface ? $user : NULL;
  }

  protected function resolveUnit(EckEntityInterface $assignment) {
    return $assignment->get('field_storage_unit')->entity;
  }

  protected function replaceTokens(string $text, array $replacements): string {
    return strtr($text, $replacements);
  }

  protected function deliver(string $event, string $to, string $langcode, string $subject, string $body): void {
    $params = [
      'subject' => $subject,
      'body' => $body,
    ];
    $result = $this->mailManager->mail('storage_manager', $event, $to, $langcode, $params);
    if (empty($result['result'])) {
      $this->logger->warning('Failed to send storage notification to @to for event @event.', ['@to' => $to, '@event' => $event]);
    }
  }

  protected function formatMoney($value): string {
    if ($value === NULL || $value === '') {
      return '0.00';
    }
    return number_format((float) $value, 2);
  }

  /**
   * Builds absolute URLs to any release photos captured on the assignment.
   */
  protected function buildReleasePhotoLinks(EckEntityInterface $assignment): array {
    if (!$assignment->hasField('field_release_photo')) {
      return [];
    }

    $links = [];
    foreach ($assignment->get('field_release_photo') as $item) {
      $file = $item->entity ?? NULL;
      if ($file) {
        $links[] = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      }
    }
    return $links;
  }

  protected function getFieldString(EckEntityInterface $assignment, string $field_name): string {
    if ($assignment->hasField($field_name)) {
      return (string) ($assignment->get($field_name)->value ?? '');
    }
    return '';
  }

  protected function getStripeCustomerId(EckEntityInterface $assignment, ?UserInterface $user): string {
    $field_candidates = [
      'field_storage_stripe_customer_id',
      'field_stripe_customer_id',
    ];
    foreach ($field_candidates as $field) {
      if ($assignment->hasField($field)) {
        $value = $assignment->get($field)->value ?? '';
        if ($value !== '') {
          return (string) $value;
        }
      }
    }
    if ($user instanceof UserInterface && $user->hasField('field_stripe_customer_id')) {
      return (string) ($user->get('field_stripe_customer_id')->value ?? '');
    }
    return '';
  }

  protected function formatDate($value): string {
    if ($value instanceof \DateTimeInterface) {
      return $value->format('Y-m-d');
    }
    if (is_numeric($value)) {
      return date('Y-m-d', (int) $value);
    }
    if (is_string($value) && $value !== '') {
      $timestamp = strtotime($value);
      if ($timestamp) {
        return date('Y-m-d', $timestamp);
      }
    }
    return '';
  }

  protected function getDefaultAdminTemplates(): array {
    return [
      'assignment' => [
        'subject' => (string) $this->t('New storage assignment: [unit_id] for [member_name]'),
        'body' => (string) $this->t("Member: [member_name] ([member_email])\nUnit: [unit_id] ([storage_type])\nMonthly: [monthly_cost]\nStart date: [assignment_start]\nStripe status: [stripe_status]\nStripe subscription: [stripe_subscription_id]\nStripe customer: [stripe_customer_id]\nManual review required: [stripe_manual_review_required]\nManual note: [stripe_manual_review_note]\n\nMember profile: [member_account_url]\nAssignment details: [assignment_admin_url]\nMember-facing view: [assignment_view_url]"),
      ],
      'release' => [
        'subject' => (string) $this->t('Storage released: [unit_id] by [member_name]'),
        'body' => (string) $this->t("Member: [member_name] ([member_email])\nUnit: [unit_id] ([storage_type])\nStart: [assignment_start]\nReleased: [release_date]\nViolations total: [violation_total_due]\nStripe status: [stripe_status]\nManual review required: [stripe_manual_review_required]\nManual note: [stripe_manual_review_note]\n\nMember profile: [member_account_url]\nAssignment details: [assignment_admin_url]\nRelease photos:\n[release_photo_links]"),
      ],
      'violation_warning' => [
        'subject' => (string) $this->t('Violation opened: [unit_id] for [member_name]'),
        'body' => (string) $this->t("Member: [member_name] ([member_email])\nUnit: [unit_id]\nViolation start: [violation_start]\nDaily rate: [violation_daily_rate]\n\nAssignment details: [assignment_admin_url]"),
      ],
      'violation_fine' => [
        'subject' => (string) $this->t('Violation fine update: [unit_id] ([member_name])'),
        'body' => (string) $this->t("Member: [member_name]\nUnit: [unit_id]\nDaily rate: [violation_daily_rate]\nTotal due: [violation_total_due]\n\nAssignment details: [assignment_admin_url]"),
      ],
      'violation_resolved' => [
        'subject' => (string) $this->t('Violation resolved: [unit_id] ([member_name])'),
        'body' => (string) $this->t("Member: [member_name]\nUnit: [unit_id]\nStart: [violation_start]\nResolved: [violation_resolved]\nTotal due: [violation_total_due]\n\nAssignment details: [assignment_admin_url]"),
      ],
    ];
  }
}

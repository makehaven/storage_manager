<?php

namespace Drupal\storage_manager\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
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
        $this->deliver($event, $address, $langcode, $subject, $body);
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
}

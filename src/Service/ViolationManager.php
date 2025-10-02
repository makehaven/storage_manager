<?php

namespace Drupal\storage_manager\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\eck\EckEntityInterface;

/**
 * Helper service to manage storage violation records.
 */
class ViolationManager {

  public function __construct(
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the active violation for an assignment, if any.
   */
  public function loadActiveViolation(int $assignment_id): ?EckEntityInterface {
    $ids = $this->violationStorage()->getQuery()
      ->condition('field_storage_vi_assignment', $assignment_id)
      ->condition('field_storage_vi_active', 1)
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    return $this->violationStorage()->load(reset($ids));
  }

  /**
   * Returns violation records for an assignment sorted by start date DESC.
   */
  public function loadViolations(int $assignment_id): array {
    $ids = $this->violationStorage()->getQuery()
      ->condition('field_storage_vi_assignment', $assignment_id)
      ->sort('field_storage_vi_start.value', 'DESC')
      ->sort('created', 'DESC')
      ->accessCheck(FALSE)
      ->execute();
    if (!$ids) {
      return [];
    }
    return $this->violationStorage()->loadMultiple($ids);
  }

  /**
   * Starts a new violation record for the given assignment.
   */
  public function startViolation(EckEntityInterface $assignment, array $values = []): EckEntityInterface {
    $start_value = $this->prepareDateValue($values['start'] ?? NULL);
    $daily_rate = $values['daily_rate'] ?? NULL;
    if ($daily_rate === '' || $daily_rate === NULL) {
      $daily_rate = $this->getDefaultDailyRate();
    }

    $violation = $this->violationStorage()->create([
      'type' => 'storage_violation',
      'field_storage_vi_assignment' => $assignment->id(),
      'field_storage_vi_active' => 1,
      'field_storage_vi_start' => $start_value,
      'field_storage_vi_daily' => $daily_rate,
      'field_storage_vi_note' => $values['note'] ?? '',
    ]);
    $violation->save();

    return $violation;
  }

  /**
   * Calculates the accrued violation charge for the violation entity.
   */
  public function calculateAccruedCharge(EckEntityInterface $violation, ?\DateTimeInterface $as_of = NULL): float {
    if (!$violation->hasField('field_storage_vi_start')) {
      return 0.0;
    }

    $daily_rate = NULL;
    if ($violation->hasField('field_storage_vi_daily')) {
      $field_value = $violation->get('field_storage_vi_daily')->value;
      if ($field_value !== NULL && $field_value !== '') {
        $daily_rate = (float) $field_value;
      }
    }

    if ($daily_rate === NULL || $daily_rate <= 0) {
      $daily_rate = $this->getDefaultDailyRate();
    }

    if ($daily_rate <= 0) {
      return 0.0;
    }

    $start_value = $violation->get('field_storage_vi_start')->value;
    if (!$start_value) {
      return 0.0;
    }

    $start = $this->createDateTime($start_value);
    if (!$start) {
      return 0.0;
    }

    $grace_period_hours = (int) $this->configFactory->get('storage_manager.settings')->get('violation.grace_period');
    $chargeable_start = $start->add(new \DateInterval("PT{$grace_period_hours}H"));

    $end = $as_of ?: $this->createDateTimeFromTimestamp($this->time->getRequestTime());

    if ($end <= $chargeable_start) {
      return 0.0;
    }

    $seconds = $end->getTimestamp() - $chargeable_start->getTimestamp();
    $days = (int) ceil($seconds / 86400);
    if ($days < 1) {
      $days = 1;
    }

    return round($days * $daily_rate, 2);
  }

  /**
   * Finalizes an active violation and stores the total due.
   */
  public function finalizeViolation(EckEntityInterface $violation, ?\DateTimeInterface $resolved_at = NULL): float {
    $resolved_at = $resolved_at ?: $this->createDateTimeFromTimestamp($this->time->getRequestTime());

    if ($violation->hasField('field_storage_vi_daily')) {
      $rate_value = $violation->get('field_storage_vi_daily')->value;
      if (($rate_value === NULL || $rate_value === '') && ($default_rate = $this->getDefaultDailyRate()) > 0) {
        $violation->set('field_storage_vi_daily', $default_rate);
      }
    }

    $total = $this->calculateAccruedCharge($violation, $resolved_at);

    if ($violation->hasField('field_storage_vi_active')) {
      $violation->set('field_storage_vi_active', 0);
    }
    if ($violation->hasField('field_storage_vi_resolved')) {
      $violation->set('field_storage_vi_resolved', $this->formatDateForStorage($resolved_at));
    }
    if ($violation->hasField('field_storage_vi_total')) {
      $violation->set('field_storage_vi_total', $total);
    }
    $violation->save();

    return $total;
  }

  /**
   * Returns TRUE if the violation entity is active.
   */
  public function isViolationActive(EckEntityInterface $violation): bool {
    return $violation->hasField('field_storage_vi_active')
      ? (bool) ($violation->get('field_storage_vi_active')->value ?? FALSE)
      : FALSE;
  }

  /**
   * Returns the configured default daily rate.
   */
  public function getDefaultDailyRate(): float {
    $value = $this->configFactory->get('storage_manager.settings')->get('violation.default_daily_rate');
    if ($value === NULL || $value === '') {
      return 0.0;
    }
    return (float) $value;
  }

  /**
   * Formats a DateTimeInterface value for datetime storage fields.
   */
  protected function formatDateForStorage(\DateTimeInterface $value): string {
    return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
  }

  /**
   * Creates an immutable datetime from a stored value.
   */
  protected function createDateTime(string $value): ?\DateTimeImmutable {
    try {
      return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  protected function createDateTimeFromTimestamp(int $timestamp): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
  }

  protected function prepareDateValue($value): string {
    if ($value instanceof DrupalDateTime) {
      return $this->formatDateForStorage($value->getPhpDateTime());
    }
    if ($value instanceof \DateTimeInterface) {
      return $this->formatDateForStorage($value);
    }
    if (is_string($value) && $value !== '') {
      try {
        return $this->formatDateForStorage(new \DateTimeImmutable($value, new \DateTimeZone('UTC')));
      }
      catch (\Exception $e) {
        // Fall through to current time.
      }
    }
    return $this->formatDateForStorage(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
  }

  protected function violationStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('storage_violation');
  }
}

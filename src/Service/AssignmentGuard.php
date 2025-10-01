<?php

namespace Drupal\storage_manager\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

class AssignmentGuard {
  public function __construct(
    private EntityTypeManagerInterface $etm,
    private LoggerInterface $logger
  ) {}

  /**
   * Returns TRUE if the unit currently has an Active assignment.
   */
  public function unitHasActiveAssignment($unit_id): bool {
    $storage = $this->etm->getStorage('storage_assignment');
    $ids = $storage->getQuery()
      ->condition('field_storage_unit', $unit_id)
      ->condition('field_storage_assignment_status', 'active')
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    return !empty($ids);
  }

  /**
   * Returns TRUE if the user already has an active storage assignment.
   */
  public function userHasActiveAssignment(int $user_id): bool {
    $storage = $this->etm->getStorage('storage_assignment');
    $ids = $storage->getQuery()
      ->condition('field_storage_user', $user_id)
      ->condition('field_storage_assignment_status', 'active')
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    return !empty($ids);
  }
}

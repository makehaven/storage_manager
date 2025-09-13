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
      ->condition('field_assignment_status', 'Active')
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    return !empty($ids);
  }
}

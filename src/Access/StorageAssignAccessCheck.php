<?php

namespace Drupal\storage_manager\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Checks access for storage assignment forms.
 */
class StorageAssignAccessCheck implements AccessInterface {

  /**
   * Checks access.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account) {
    // User 1 is the super user and should always have access.
    // The result should not be cached for user 1.
    if ((int) $account->id() === 1) {
      return AccessResult::allowed()->setCacheable(FALSE);
    }

    // Check for the administrative permission first.
    if ($account->hasPermission('manage storage')) {
      return AccessResult::allowed();
    }

    // Then check for the self-service permission.
    if ($account->hasPermission('claim or release own storage')) {
      return AccessResult::allowed();
    }

    // If none of the above, deny access.
    return AccessResult::neutral();
  }

}
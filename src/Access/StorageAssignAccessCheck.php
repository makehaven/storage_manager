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
    // Check if the user has either the admin permission or the self-service permission.
    return AccessResult::allowedIfHasPermissions($account, ['manage storage', 'claim or release own storage'], 'OR');
  }

}
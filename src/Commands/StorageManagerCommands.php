<?php

namespace Drupal\storage_manager\Commands;

use Drush\Commands\DrushCommands;
use RuntimeException;

/**
 * Drush commands for Storage Manager.
 */
class StorageManagerCommands extends DrushCommands {

  /**
   * Import Storage Manager configuration and seed defaults.
   *
   * @command storage_manager:import-config
   * @aliases storage_manager:configure sm:import-config
   * @bootstrap full
   */
  public function importConfig(): void {
    if (!\Drupal::moduleHandler()->moduleExists('storage_manager')) {
      throw new RuntimeException('Enable the storage_manager module before running this command.');
    }

    $module_path = \Drupal::service('extension.list.module')->getPath('storage_manager');
    $install_path = DRUPAL_ROOT . '/' . $module_path . '/storage_manager.install';
    if (!file_exists($install_path)) {
      throw new RuntimeException('storage_manager.install file not found at ' . $install_path);
    }
    require_once $install_path;

    \storage_manager_import_config();
    \_storage_manager_seed_storage_areas();
    \_storage_manager_seed_storage_types();

    $this->logger()->success('Storage Manager configuration imported successfully.');
  }

}

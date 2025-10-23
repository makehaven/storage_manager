<?php

namespace Drupal\storage_manager;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Modifies the subscription manager service depending on whether mh_stripe is enabled.
 */
class StorageManagerServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    // Overrides services from storage_manager.services.yml to point to null
    // handlers if the mh_stripe module is not enabled.
    $module_handler = $container->get('module_handler');
    if (!$module_handler->moduleExists('mh_stripe')) {
      $definition = $container->getDefinition('storage_manager.subscription_manager');
      $definition->setClass('Drupal\storage_manager\Stripe\NullSubscriptionManager');
      $definition->setArguments([]);
    }
  }

}

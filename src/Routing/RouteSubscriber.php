<?php

namespace Drupal\storage_manager\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    //
    // Gated by module exists to prevent fatal errors if the module is uninstalled
    // but the container is not rebuilt.
    //
    $module_handler = \Drupal::moduleHandler();
    if (!$module_handler->moduleExists('mh_stripe')) {
      if ($route = $collection->get('storage_manager_billing.sub_create_or_open')) {
        $route->setRequirement('_access', 'FALSE');
      }
      if ($route = $collection->get('storage_manager_billing.portal')) {
        $route->setRequirement('_access', 'FALSE');
      }
    }
  }

}

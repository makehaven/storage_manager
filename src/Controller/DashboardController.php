<?php

namespace Drupal\storage_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

class DashboardController extends ControllerBase {

  public function view(): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['storage-dashboard']],
    ];

    $u_storage = $this->entityTypeManager()->getStorage('storage_unit');
    $ids = $u_storage->getQuery()->accessCheck(FALSE)->execute();
    $units = $u_storage->loadMultiple($ids);

    $rows = [];
    foreach ($units as $unit) {
      $status = $unit->get('field_storage_status')->value;
      $name = $unit->label();

      $assign_link = Link::fromTextAndUrl('Assign',
        Url::fromRoute('storage_manager.assign_form', ['unit' => $unit->id()]))->toString();

      $release_link = Link::fromTextAndUrl('Release',
        Url::fromRoute('storage_manager.release_form', ['unit' => $unit->id()]))->toString();

      // Show “Release” only if occupied.
      $actions = ($status === 'Occupied') ? $release_link : $assign_link;

      $rows[] = [
        $name,
        $status,
        $unit->get('field_storage_area')->entity?->label() ?? '-',
        $unit->get('field_storage_type')->entity?->label() ?? '-',
        $actions,
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => ['Unit', 'Status', 'Area', 'Type', 'Action'],
      '#rows' => $rows,
      '#empty' => $this->t('No units found.'),
    ];

    return $build;
  }
}

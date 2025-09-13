<?php

namespace Drupal\storage_manager\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * @Block(
 *   id = "storage_map_block",
 *   admin_label = @Translation("Storage Map")
 * )
 */
class StorageMapBlock extends BlockBase {
  public function build(): array {
    $u_storage = \Drupal::entityTypeManager()->getStorage('storage_unit');
    $ids = $u_storage->getQuery()->accessCheck(FALSE)->execute();
    $units = $u_storage->loadMultiple($ids);

    $items = [];
    foreach ($units as $unit) {
      $x = (int) ($unit->get('field_storage_x')->value ?? -1);
      $y = (int) ($unit->get('field_storage_y')->value ?? -1);
      if ($x < 0 || $y < 0) { continue; }

      $status = $unit->get('field_storage_status')->value ?? 'Vacant';
      $color = match ($status) {
        'Occupied' => '#d9534f',   // red
        'Reserved' => '#f0ad4e',   // orange
        'Vacant'   => '#5cb85c',   // green
        default    => '#777'
      };

      $items[] = [
        'label' => $unit->label(),
        'x' => $x, 'y' => $y, 'color' => $color,
        'status' => $status,
      ];
    }

    return [
      '#theme' => 'storage_map',
      '#units' => $items,
      '#attached' => [
        'library' => ['storage_manager/map'],
      ],
    ];
  }
}

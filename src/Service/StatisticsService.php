<?php

namespace Drupal\storage_manager\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service for calculating storage statistics.
 */
class StatisticsService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new StatisticsService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Calculate and return storage statistics.
   *
   * @return array
   *   An array of statistics.
   */
  public function getStatistics(): array {
    $unit_storage = $this->entityTypeManager->getStorage('storage_unit');
    $assignment_storage = $this->entityTypeManager->getStorage('storage_assignment');

    $all_units = $unit_storage->loadMultiple($unit_storage->getQuery()->accessCheck(TRUE)->execute());

    $assignment_field_defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('storage_assignment', 'storage_assignment');
    $has_status_field = isset($assignment_field_defs['field_storage_assignment_status']);

    $active_assignments_query = $assignment_storage->getQuery()->accessCheck(TRUE);
    if ($has_status_field) {
      $active_assignments_query->condition('field_storage_assignment_status', 'active');
    }
    $active_assignment_ids = $active_assignments_query->execute();
    $active_assignments = $assignment_storage->loadMultiple($active_assignment_ids);

    $stats = [
      'overall' => [
        'total_units' => 0,
        'occupied_units' => 0,
        'vacant_units' => 0,
        'vacancy_rate' => 0,
        'total_value' => 0,
        'billed_value' => 0,
        'complimentary_value' => 0,
        'potential_value' => 0,
      ],
      'by_type' => [],
      'by_area' => [],
    ];

    $occupied_unit_ids = [];
    foreach ($active_assignments as $assignment) {
      $unit_id = $assignment->get('field_storage_unit')->target_id;
      if ($unit_id) {
        $occupied_unit_ids[$unit_id] = $assignment;
      }
    }

    $stats['overall']['total_units'] = count($all_units);
    $stats['overall']['occupied_units'] = count($occupied_unit_ids);
    $stats['overall']['vacant_units'] = $stats['overall']['total_units'] - $stats['overall']['occupied_units'];
    if ($stats['overall']['total_units'] > 0) {
      $stats['overall']['vacancy_rate'] = ($stats['overall']['vacant_units'] / $stats['overall']['total_units']) * 100;
    }

    foreach ($all_units as $unit) {
      $type_entity = $unit->get('field_storage_type')->entity;
      $area_entity = $unit->get('field_storage_area')->entity;
      $type_name = $type_entity?->label() ?? 'N/A';
      $area_name = $area_entity?->label() ?? 'N/A';
      $price = (float) ($type_entity?->get('field_monthly_price')->value ?? 0);

      // Initialize breakdown arrays
      if (!isset($stats['by_type'][$type_name])) {
        $stats['by_type'][$type_name] = ['total_units' => 0, 'occupied_units' => 0, 'potential_value' => 0];
      }
      if (!isset($stats['by_area'][$area_name])) {
        $stats['by_area'][$area_name] = ['total_units' => 0, 'occupied_units' => 0, 'potential_value' => 0];
      }

      $stats['by_type'][$type_name]['total_units']++;
      $stats['by_area'][$area_name]['total_units']++;

      $is_occupied = isset($occupied_unit_ids[$unit->id()]);
      if ($is_occupied) {
        $stats['by_type'][$type_name]['occupied_units']++;
        $stats['by_area'][$area_name]['occupied_units']++;

        $assignment = $occupied_unit_ids[$unit->id()];
        if ($assignment->hasField('field_storage_complimentary') && (bool) $assignment->get('field_storage_complimentary')->value) {
          $stats['overall']['complimentary_value'] += $price;
        }
        else {
          $stats['overall']['billed_value'] += $price;
        }
      }
      else {
        // Vacant unit, contributes to potential value
        $stats['overall']['potential_value'] += $price;
        $stats['by_type'][$type_name]['potential_value'] += $price;
        $stats['by_area'][$area_name]['potential_value'] += $price;
      }
    }

    $stats['overall']['total_value'] = $stats['overall']['billed_value'] + $stats['overall']['complimentary_value'];

    // Calculate vacancy rates for breakdowns
    foreach ($stats['by_type'] as &$type_data) {
      $vacant = $type_data['total_units'] - $type_data['occupied_units'];
      $type_data['vacancy_rate'] = $type_data['total_units'] > 0 ? ($vacant / $type_data['total_units']) * 100 : 0;
    }

    foreach ($stats['by_area'] as &$area_data) {
      $vacant = $area_data['total_units'] - $area_data['occupied_units'];
      $area_data['vacancy_rate'] = $area_data['total_units'] > 0 ? ($vacant / $area_data['total_units']) * 100 : 0;
    }

    return $stats;
  }

}

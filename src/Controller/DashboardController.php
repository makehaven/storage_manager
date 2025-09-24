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

    $build['intro'] = [
      '#markup' => $this->t('<p>This dashboard provides an overview of all storage units. "Areas" are the physical locations of the units, and "Types" are the different sizes or kinds of units available.</p>'),
      '#weight' => -20,
    ];

    $build['taxonomy_links'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['taxonomy-links']],
      '#weight' => -10,
    ];

    $build['taxonomy_links']['storage_area'] = [
      '#type' => 'link',
      '#title' => $this->t('Manage Storage Areas'),
      '#url' => Url::fromRoute('entity.taxonomy_vocabulary.overview_form', ['taxonomy_vocabulary' => 'storage_area']),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $build['taxonomy_links']['storage_type'] = [
      '#type' => 'link',
      '#title' => $this->t('Manage Storage Types'),
      '#url' => Url::fromRoute('entity.taxonomy_vocabulary.overview_form', ['taxonomy_vocabulary' => 'storage_type']),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $build['add_unit_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Add Storage Unit'),
      '#url' => Url::fromRoute('eck.entity.add', ['eck_entity_type' => 'storage_unit', 'eck_entity_bundle' => 'storage_unit']),
      '#attributes' => ['class' => ['button', 'button--action']],
      '#weight' => -9,
    ];

    $build['assignment_history_link'] = [
      '#type' => 'link',
      '#title' => $this->t('View Assignment History'),
      '#url' => Url::fromRoute('storage_manager.assignment_history'),
      '#attributes' => ['class' => ['button']],
      '#weight' => -8,
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
      if ($status === 'Occupied') {
        $assignment_id = \Drupal::entityQuery('storage_assignment')
          ->condition('field_storage_unit', $unit->id())
          ->condition('field_storage_assignment_status', 'Active')
          ->accessCheck(FALSE)
          ->execute();
        $assignment_id = reset($assignment_id);
        $edit_assignment_link = Link::fromTextAndUrl('Edit Assignment',
          Url::fromRoute('storage_manager.edit_assignment_form', ['assignment' => $assignment_id]))->toString();
        $actions = $release_link . ' ' . $edit_assignment_link;
      } else {
        $actions = $assign_link;
      }

      $edit_link = Link::fromTextAndUrl('Edit',
        Url::fromRoute('eck.entity.edit', ['eck_entity_type' => 'storage_unit', 'eck_entity_bundle' => 'storage_unit', 'eck_entity_id' => $unit->id()]))->toString();
      $actions .= ' ' . $edit_link;

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

  public function history(): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['storage-history']],
    ];

    $a_storage = $this->entityTypeManager()->getStorage('storage_assignment');
    $ids = $a_storage->getQuery()->accessCheck(FALSE)->execute();
    $assignments = $a_storage->loadMultiple($ids);

    $rows = [];
    foreach ($assignments as $assignment) {
      $rows[] = [
        $assignment->get('field_storage_unit')->entity?->label() ?? '-',
        $assignment->get('field_storage_user')->entity?->label() ?? '-',
        $assignment->get('field_storage_start_date')->value,
        $assignment->get('field_storage_end_date')->value,
        $assignment->get('field_storage_assignment_status')->value,
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => ['Unit', 'User', 'Start Date', 'End Date', 'Status'],
      '#rows' => $rows,
      '#empty' => $this->t('No assignments found.'),
    ];

    return $build;
  }
}

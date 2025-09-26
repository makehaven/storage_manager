<?php

namespace Drupal\storage_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Render\Markup;
use Drupal\Core\Entity\EntityTypeInterface;

/**
 * Storage dashboard and history pages.
 */
class DashboardController extends ControllerBase {

  /**
   * Dashboard listing.
   */
  public function view(): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['storage-dashboard']],
    ];

    // Top buttons: manage vocabularies and add unit.
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
      '#url' => Url::fromRoute('eck.entity.add', [
        'eck_entity_type' => 'storage_unit',
        'eck_entity_bundle' => 'storage_unit',
      ]),
      '#attributes' => ['class' => ['button', 'button--action']],
      '#weight' => -9,
    ];

    $build['history_link'] = [
      '#type' => 'link',
      '#title' => $this->t('View Assignment History'),
      '#url' => Url::fromRoute('storage_manager.history'),
      '#attributes' => ['class' => ['button']],
      '#weight' => -8,
    ];

    // Load units and build the table.
    $u_storage = $this->entityTypeManager()->getStorage('storage_unit');
    $ids = $u_storage->getQuery()->accessCheck(FALSE)->execute();
    $units = $u_storage->loadMultiple($ids);

    // Load all active assignments, keyed by their unit ID for efficiency.
    $assignment_storage = $this->entityTypeManager()->getStorage('storage_assignment');
    $assignment_ids = $assignment_storage->getQuery()
      ->condition('field_storage_end_date', NULL, 'IS NULL')
      ->accessCheck(FALSE)
      ->execute();
    $active_assignments = $assignment_storage->loadMultiple($assignment_ids);
    $assignments_by_unit = [];
    foreach ($active_assignments as $assignment) {
      if ($unit_id = $assignment->get('field_storage_unit')->target_id) {
        $assignments_by_unit[$unit_id] = $assignment;
      }
    }

    $rows = [];
    foreach ($units as $unit) {
      $status = $unit->get('field_storage_status')->value;
      $name = $unit->label();

      $action_items = [];

      // Add "Edit Unit" link for all units.
      $action_items[] = Link::fromTextAndUrl(
        $this->t('Edit Unit'),
        Url::fromRoute('entity.storage_unit.edit_form', ['storage_unit' => $unit->id()])
      )->toString();

      if ($status === 'Occupied') {
        // For occupied units, add Release and Edit Assignment links.
        $action_items[] = Link::fromTextAndUrl(
          $this->t('Release'),
          Url::fromRoute('storage_manager.release_form', ['unit' => $unit->id()])
        )->toString();

        if (isset($assignments_by_unit[$unit->id()])) {
          $assignment = $assignments_by_unit[$unit->id()];
          $action_items[] = Link::fromTextAndUrl(
            $this->t('Edit Assignment'),
            Url::fromRoute('entity.storage_assignment.edit_form', ['storage_assignment' => $assignment->id()])
          )->toString();
        }
      }
      else {
        // For vacant units, add Assign link.
        $action_items[] = Link::fromTextAndUrl(
          $this->t('Assign'),
          Url::fromRoute('storage_manager.assign_form', ['unit' => $unit->id()])
        )->toString();
      }

      $actions = implode(' | ', $action_items);

      $rows[] = [
        $name,
        $status,
        $unit->get('field_storage_area')->entity?->label() ?? '-',
        $unit->get('field_storage_type')->entity?->label() ?? '-',
        ['data' => Markup::create($actions)],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Unit'), $this->t('Status'), $this->t('Area'), $this->t('Type'), $this->t('Action')],
      '#rows' => $rows,
      '#empty' => $this->t('No units found.'),
    ];

    return $build;
  }

  /**
   * Assignment history page.
   */
  public function history() {
    $storage = $this->entityTypeManager()->getStorage('storage_assignment');
    $assignments = $storage->loadMultiple();

    $rows = [];
    foreach ($assignments as $a) {
      $unit = $a->get('field_storage_unit')->entity;
      $user = $a->get('field_storage_user')->entity;
      $start = $a->get('field_storage_start_date')->value ? date('Y-m-d', strtotime($a->get('field_storage_start_date')->value)) : '—';
      $end = $a->get('field_storage_end_date')->value ? date('Y-m-d', strtotime($a->get('field_storage_end_date')->value)) : '—';
      $note = $a->get('field_storage_issue_note')->value ?? '';

      $rows[] = [
        $unit?->get('field_storage_unit_id')->value ?? '—',
        $user?->label() ?? '—',
        $start,
        $end,
        $note,
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Unit ID'),
        $this->t('Member'),
        $this->t('Start'),
        $this->t('Release'),
        $this->t('Notes'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No assignments found.'),
    ];
  }

}

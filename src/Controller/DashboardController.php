<?php

namespace Drupal\storage_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Storage dashboard and history pages.
 */
class DashboardController extends ControllerBase {

  /**
   * Dashboard listing.
   */
  public function view() {
    $build = [];

    // Header buttons.
    $buttons = [
      Link::fromTextAndUrl($this->t('Manage Storage Areas'),
        Url::fromRoute('entity.taxonomy_vocabulary.overview_form', ['taxonomy_vocabulary' => 'storage_area'])
      )->toRenderable() + ['#attributes' => ['class' => ['button']]],
      Link::fromTextAndUrl($this->t('Manage Storage Types'),
        Url::fromRoute('entity.taxonomy_vocabulary.overview_form', ['taxonomy_vocabulary' => 'storage_type'])
      )->toRenderable() + ['#attributes' => ['class' => ['button']]],
      Link::fromTextAndUrl($this->t('Add Storage Unit'),
        Url::fromRoute('eck.entity.add', [
          'eck_entity_type' => 'storage_unit',
          'eck_entity_bundle' => 'storage_unit',
        ], [
          'query' => ['destination' => '/admin/storage'],
        ])
      )->toRenderable() + ['#attributes' => ['class' => ['button', 'button--primary']]],
      Link::fromTextAndUrl($this->t('View Assignment History'),
        Url::fromRoute('storage_manager.history')
      )->toRenderable() + ['#attributes' => ['class' => ['button']]],
    ];
    $build['actions'] = [
      '#theme' => 'item_list',
      '#items' => $buttons,
      '#attributes' => ['class' => ['inline', 'mb-4', 'storage-dashboard-actions']],
    ];

    // Load all storage units.
    $u_storage = $this->entityTypeManager()->getStorage('storage_unit');
    $uids = $u_storage->getQuery()->accessCheck(TRUE)->execute();
    $units = $u_storage->loadMultiple($uids);

    // For "active" assignment lookup.
    $a_storage = $this->entityTypeManager()->getStorage('storage_assignment');
    $efm = \Drupal::service('entity_field.manager');
    $assign_defs = $efm->getFieldDefinitions('storage_assignment', 'storage_assignment');
    $has_status = isset($assign_defs['field_storage_assignment_status']);

    $rows = [];
    foreach ($units as $unit) {
      $unit_id = $unit->get('field_storage_unit_id')->value ?: $this->t('—');
      $area = $unit->get('field_storage_area')->entity?->label() ?? $this->t('—');
      $type = $unit->get('field_storage_type')->entity?->label() ?? $this->t('—');
      $status = $unit->get('field_storage_status')->value ?? $this->t('—');
      $member = $this->t('—');

      // Query the current (active) assignment for this unit.
      $aq = $a_storage->getQuery()->accessCheck(TRUE);
      $aq->condition('field_storage_unit.target_id', $unit->id());
      if ($has_status) {
        // Machine value is typically lowercase.
        $aq->condition('field_storage_assignment_status', 'active');
      }
      $aid = $aq->range(0, 1)->execute();
      $assignment = $aid ? $a_storage->load(reset($aid)) : NULL;
      if ($assignment) {
        $member = $assignment->get('field_storage_user')->entity?->label() ?? $this->t('—');
      }

      $ops = [];
      // Edit Unit.
      $ops[] = Link::fromTextAndUrl($this->t('Edit Unit'),
        Url::fromRoute('entity.storage_unit.edit_form', ['storage_unit' => $unit->id()])
      )->toString();

      if ($assignment) {
        $ops[] = Link::fromTextAndUrl($this->t('Release Unit'),
          Url::fromRoute('storage_manager.unit_release', ['storage_unit' => $unit->id()], [
            'query' => ['destination' => '/admin/storage'],
          ])
        )->toString();
      }
      else {
        $ops[] = Link::fromTextAndUrl($this->t('Assign Unit'),
          Url::fromRoute('storage_manager.unit_assign', ['storage_unit' => $unit->id()], [
            'query' => ['destination' => '/admin/storage'],
          ])
        )->toString();
      }

      // If there is an active assignment, offer "Edit Assignment".
      if ($assignment) {
        $ops[] = Link::fromTextAndUrl($this->t('Edit Assignment'),
          Url::fromRoute('entity.storage_assignment.edit_form', ['storage_assignment' => $assignment->id()])
        )->toString();
      }

      $rows[] = [
        $unit_id,
        $area,
        $type,
        $status,
        $member,
        ['data' => ['#markup' => implode(' | ', $ops)]],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Unit ID'),
        $this->t('Area'),
        $this->t('Type'),
        $this->t('Status'),
        $this->t('Member'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No storage units found.'),
    ];

    return $build;
  }

  /**
   * Assignment history page.
   */
  public function history() {
    $storage = $this->entityTypeManager()->getStorage('storage_assignment');
    $aids = $storage->getQuery()->accessCheck(TRUE)->sort('created', 'DESC')->execute();
    $assignments = $storage->loadMultiple($aids);

    $rows = [];
    foreach ($assignments as $a) {
      $unit = $a->get('field_storage_unit')->entity;
      $user = $a->get('field_storage_user')->entity;

      // start_date: date string (Y-m-d). end_date: datetime string.
      $start_raw = $a->get('field_storage_start_date')->value; // e.g., '2025-09-30'
      $end_raw = $a->get('field_storage_end_date')->value;     // e.g., '2025-09-30T12:34:00'

      $start = $start_raw ?: '—';
      if ($end_raw) {
        $end_ts = strtotime($end_raw);
        $end = $end_ts ? date('Y-m-d', $end_ts) : $end_raw;
      } else {
        $end = '—';
      }

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

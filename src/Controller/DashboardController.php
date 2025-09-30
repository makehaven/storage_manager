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
    $sort_field = \Drupal::request()->query->get('sort') ?: 'unit';
    $sort_dir = strtolower(\Drupal::request()->query->get('direction') ?: 'asc') === 'desc' ? 'desc' : 'asc';
    $current_sort = ['field' => $sort_field, 'direction' => $sort_dir];

    $build = [];

    // Header buttons.
    $build['links'] = [
      '#theme' => 'item_list',
      '#items' => [
        Link::fromTextAndUrl($this->t('Manage Storage Areas'),
          Url::fromRoute('entity.taxonomy_vocabulary.overview_form', ['taxonomy_vocabulary' => 'storage_area'])
        )->toRenderable(),
        Link::fromTextAndUrl($this->t('Manage Storage Types'),
          Url::fromRoute('entity.taxonomy_vocabulary.overview_form', ['taxonomy_vocabulary' => 'storage_type'])
        )->toRenderable(),
      ],
      '#attributes' => ['class' => ['inline', 'mb-2', 'storage-dashboard-links']],
    ];

    $buttons = [
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
        $member_entity = $assignment->get('field_storage_user')->entity;
        if ($member_entity) {
          $member = Link::fromTextAndUrl(
            $member_entity->label(),
            Url::fromRoute('storage_manager.history', [], [
              'query' => ['user' => $member_entity->id()],
            ])
          )->toString();
        }
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

    $header = [
      $this->buildSortableHeader('unit', $this->t('Unit ID'), $current_sort),
      $this->buildSortableHeader('area', $this->t('Area'), $current_sort),
      $this->buildSortableHeader('type', $this->t('Type'), $current_sort),
      $this->buildSortableHeader('status', $this->t('Status'), $current_sort),
      $this->buildSortableHeader('member', $this->t('Member'), $current_sort),
      $this->t('Operations'),
    ];

    if ($current_sort['field']) {
      $rows = $this->sortRows($rows, $current_sort['field'], $current_sort['direction']);
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No storage units found.'),
    ];

    return $build;
  }

  /**
   * Assignment history page.
   */
  public function history() {
    $request = \Drupal::request();
    $filter_uid = $request->query->get('user');

    $storage = $this->entityTypeManager()->getStorage('storage_assignment');
    $query = $storage->getQuery()->accessCheck(TRUE)->sort('created', 'DESC');
    if ($filter_uid) {
      $query->condition('field_storage_user', $filter_uid);
    }
    $aids = $query->execute();
    $assignments = $storage->loadMultiple($aids);

    $build = [];

    if ($filter_uid) {
      $user = $this->entityTypeManager()->getStorage('user')->load($filter_uid);
      if ($user) {
        $clear_link = Link::fromTextAndUrl($this->t('Clear filter'), Url::fromRoute('storage_manager.history'))->toRenderable();
        $clear_link['#attributes']['class'][] = 'button';
        $clear_link['#attributes']['class'][] = 'button--small';

        $user_link = Link::fromTextAndUrl($user->label(), Url::fromRoute('entity.user.canonical', ['user' => $user->id()]))
          ->toRenderable();
        $user_link['#attributes']['class'][] = 'storage-history-user-link';

        $build['filter_info'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['messages', 'messages--status', 'storage-history-filter']],
          'text' => [
            '#type' => 'inline_template',
            '#template' => 'Showing assignments for {{ user_link|raw }}.',
            '#context' => ['user_link' => Link::fromTextAndUrl($user->label(), Url::fromRoute('entity.user.canonical', ['user' => $user->id()]))->toString()],
          ],
          'clear' => $clear_link,
        ];
      }
    }

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

    $build['table'] = [
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

    return $build;
  }

  /**
   * Helper to build a sortable column header link.
   */
  protected function buildSortableHeader(string $key, $label, array $current): array {
    $direction = 'asc';
    if ($current['field'] === $key && $current['direction'] === 'asc') {
      $direction = 'desc';
    }
    $url = Url::fromRoute('storage_manager.dashboard', [], [
      'query' => ['sort' => $key, 'direction' => $direction],
    ]);

    $attributes = [];
    if ($current['field'] === $key) {
      $attributes['class'][] = 'is-active';
    }

    $link = Link::fromTextAndUrl($label, $url)->toRenderable();
    if ($attributes) {
      $link['#attributes'] = array_merge($link['#attributes'] ?? [], $attributes);
    }

    return ['data' => $link];
  }

  /**
   * Sorts table rows client-side according to selected header.
   */
  protected function sortRows(array $rows, string $field, string $direction): array {
    $index_map = [
      'unit' => 0,
      'area' => 1,
      'type' => 2,
      'status' => 3,
      'member' => 4,
    ];
    if (!isset($index_map[$field])) {
      return $rows;
    }

    $column_index = $index_map[$field];
    usort($rows, function ($a, $b) use ($column_index, $direction) {
      $value_a = is_array($a[$column_index]) ? (string) ($a[$column_index]['data']['#markup'] ?? '') : (string) $a[$column_index];
      $value_b = is_array($b[$column_index]) ? (string) ($b[$column_index]['data']['#markup'] ?? '') : (string) $b[$column_index];

      $result = strcasecmp($value_a, $value_b);
      return $direction === 'desc' ? -$result : $result;
    });

    return $rows;
  }

}

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
    $build['#attached']['library'][] = 'storage_manager/admin';
    $build['hub_links'] = $this->buildHubLinks();

    $unit_storage = $this->entityTypeManager()->getStorage('storage_unit');
    $unit_ids = $unit_storage->getQuery()->accessCheck(TRUE)->execute();
    $units = $unit_storage->loadMultiple($unit_ids);

    $assignment_storage = $this->entityTypeManager()->getStorage('storage_assignment');
    $field_manager = \Drupal::service('entity_field.manager');
    $assignment_field_defs = $field_manager->getFieldDefinitions('storage_assignment', 'storage_assignment');
    $has_status = isset($assignment_field_defs['field_storage_assignment_status']);

    $violation_manager = \Drupal::service('storage_manager.violation_manager');
    $rows = [];

    foreach ($units as $unit) {
      $unit_id = $unit->get('field_storage_unit_id')->value ?: $this->t('—');
      $area = $unit->get('field_storage_area')->entity?->label() ?? $this->t('—');
      $type_entity = $unit->get('field_storage_type')->entity;
      $type = $type_entity?->label() ?? $this->t('—');
      $monthly_cost_value = $type_entity?->get('field_monthly_price')->value;
      $monthly_cost = $monthly_cost_value !== NULL && $monthly_cost_value !== ''
        ? '$' . number_format((float) $monthly_cost_value, 2)
        : $this->t('—');
      $status_value = $unit->get('field_storage_status')->value;
      $status_label = $status_value ? ucfirst($status_value) : $this->t('—');
      $member = $this->t('—');
      $violation_summary = $this->t('—');

      $assignment_query = $assignment_storage->getQuery()->accessCheck(TRUE);
      $assignment_query->condition('field_storage_unit.target_id', $unit->id());
      if ($has_status) {
        $assignment_query->condition('field_storage_assignment_status', 'active');
      }
      $assignment_id = $assignment_query->range(0, 1)->execute();
      $assignment = $assignment_id ? $assignment_storage->load(reset($assignment_id)) : NULL;

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

        if ($assignment->hasField('field_storage_complimentary') && (bool) $assignment->get('field_storage_complimentary')->value) {
          $monthly_cost = $this->t('Complimentary');
        }

        $active_violation = $violation_manager->loadActiveViolation((int) $assignment->id());
        if ($active_violation) {
          $daily = $active_violation->get('field_storage_vi_daily')->value ?? $violation_manager->getDefaultDailyRate();
          $accrued = $violation_manager->calculateAccruedCharge($active_violation);
          if ($daily > 0) {
            $violation_summary = $this->t('Active: $@daily/day (accrued $@accrued)', [
              '@daily' => number_format((float) $daily, 2),
              '@accrued' => number_format($accrued, 2),
            ]);
          }
          else {
            $violation_summary = $this->t('Active violation');
          }
        }
        else {
          $violations = $violation_manager->loadViolations((int) $assignment->id());
          $latest = $violations[0] ?? NULL;
          if ($latest && !$violation_manager->isViolationActive($latest)) {
            $total_due = $latest->get('field_storage_vi_total')->value;
            $resolved = $latest->get('field_storage_vi_resolved')->value;
            if ($resolved) {
              $resolved_date = date('Y-m-d', strtotime($resolved));
              $violation_summary = $this->t('Resolved @date · $@amount due', [
                '@date' => $resolved_date,
                '@amount' => number_format((float) $total_due, 2),
              ]);
            }
            elseif ($total_due !== NULL && $total_due !== '') {
              $violation_summary = $this->t('Resolved · $@amount due', ['@amount' => number_format((float) $total_due, 2)]);
            }
          }
        }
      }

      $ops = [];
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

      if ($assignment) {
        $edit_label = $violation_manager->loadActiveViolation((int) $assignment->id())
          ? $this->t('Resolve Violation')
          : $this->t('Edit Assignment');
        $ops[] = Link::fromTextAndUrl($edit_label,
          Url::fromRoute('storage_manager.assignment_edit', ['storage_assignment' => $assignment->id()])
        )->toString();
      }

      $status_render = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $status_label,
        '#attributes' => [
          'class' => [
            'storage-manager-status-pill',
            'storage-manager-status-pill--' . ($status_value ?: 'unknown'),
          ],
        ],
      ];

      $rows[] = [
        $unit_id,
        $area,
        $type,
        $monthly_cost,
        ['data' => $status_render],
        $violation_summary,
        $member,
        ['data' => ['#markup' => implode(' | ', $ops)]],
      ];
    }

    $header = [
      $this->buildSortableHeader('unit', $this->t('Unit ID'), $current_sort),
      $this->buildSortableHeader('area', $this->t('Area'), $current_sort),
      $this->buildSortableHeader('type', $this->t('Type'), $current_sort),
      $this->buildSortableHeader('cost', $this->t('Monthly Cost'), $current_sort),
      $this->buildSortableHeader('status', $this->t('Status'), $current_sort),
      $this->buildSortableHeader('violation', $this->t('Violation'), $current_sort),
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

    $violation_manager = \Drupal::service('storage_manager.violation_manager');
    $rows = [];
    foreach ($assignments as $assignment) {
      $unit = $assignment->get('field_storage_unit')->entity;
      $user = $assignment->get('field_storage_user')->entity;

      $start_raw = $assignment->get('field_storage_start_date')->value;
      $end_raw = $assignment->get('field_storage_end_date')->value;

      $start = $start_raw ?: '—';
      if ($end_raw) {
        $end_ts = strtotime($end_raw);
        $end = $end_ts ? date('Y-m-d', $end_ts) : $end_raw;
      }
      else {
        $end = '—';
      }

      $note = $assignment->get('field_storage_issue_note')->value ?? '';
      $violations = $violation_manager->loadViolations((int) $assignment->id());
      $active_violation = NULL;
      foreach ($violations as $candidate) {
        if ($violation_manager->isViolationActive($candidate)) {
          $active_violation = $candidate;
          break;
        }
      }

      $violation_label = '—';
      if ($active_violation) {
        $daily = $active_violation->get('field_storage_vi_daily')->value ?? $violation_manager->getDefaultDailyRate();
        $violation_label = $daily > 0
          ? $this->t('Active · $@daily/day', ['@daily' => number_format((float) $daily, 2)])
          : $this->t('Active');
      }
      elseif (!empty($violations)) {
        $latest = $violations[0];
        $total_due = $latest->get('field_storage_vi_total')->value;
        $violation_label = $total_due !== NULL && $total_due !== ''
          ? $this->t('Resolved · $@amount', ['@amount' => number_format((float) $total_due, 2)])
          : $this->t('Resolved');
      }

      $rows[] = [
        ($unit ? $unit->get('field_storage_unit_id')->value : NULL) ?? '—',
        $user?->label() ?? '—',
        $start,
        $end,
        $violation_label,
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
        $this->t('Violation'),
        $this->t('Notes'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No assignments found.'),
    ];

    return $build;
  }

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

  protected function sortRows(array $rows, string $field, string $direction): array {
    $index_map = [
      'unit' => 0,
      'area' => 1,
      'type' => 2,
      'cost' => 3,
      'status' => 4,
      'violation' => 5,
      'member' => 6,
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

  protected function buildHubLinks(): array {
    $sections = [
      'operations' => [
        'heading' => $this->t('Operations'),
        'links' => [
          [
            'title' => $this->t('Add storage unit'),
            'url' => Url::fromRoute('eck.entity.add', [
              'eck_entity_type' => 'storage_unit',
              'eck_entity_bundle' => 'storage_unit',
            ], [
              'query' => ['destination' => '/admin/storage'],
            ]),
            'attributes' => ['class' => ['button', 'button--primary']],
          ],
          [
            'title' => $this->t('Assignment & violation history'),
            'url' => Url::fromRoute('storage_manager.history'),
          ],
        ],
      ],
      'configuration' => [
        'heading' => $this->t('Configuration'),
        'links' => [
          [
            'title' => $this->t('Storage settings'),
            'url' => Url::fromRoute('storage_manager.settings'),
          ],
          [
            'title' => $this->t('Manage storage areas'),
            'url' => Url::fromRoute('entity.taxonomy_vocabulary.overview_form', ['taxonomy_vocabulary' => 'storage_area']),
          ],
          [
            'title' => $this->t('Manage storage types'),
            'url' => Url::fromRoute('entity.taxonomy_vocabulary.overview_form', ['taxonomy_vocabulary' => 'storage_type']),
          ],
        ],
      ],
      'member' => [
        'heading' => $this->t('Member-facing'),
        'links' => [
          [
            'title' => $this->t('Member claim page (@path)', ['@path' => '/storage/claim']),
            'url' => Url::fromRoute('storage_manager.claim'),
            'attributes' => ['class' => ['storage-manager-link-highlight']],
          ],
          [
            'title' => $this->t('Self-service storage dashboard'),
            'url' => Url::fromRoute('storage_manager.member_dashboard'),
          ],
        ],
      ],
    ];

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['storage-manager-hub']],
    ];

    foreach ($sections as $key => $section) {
      $build[$key . '_heading'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $section['heading'],
        '#attributes' => ['class' => ['storage-manager-hub__heading']],
      ];
      $build[$key . '_links'] = [
        '#theme' => 'links',
        '#attributes' => ['class' => ['storage-manager-links', 'storage-manager-links--' . $key]],
        '#links' => $section['links'],
      ];
    }

    return $build;
  }
}

<?php

namespace Drupal\storage_manager\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\mh_stripe\Service\StripeHelper;
use Drupal\storage_manager\Service\StatisticsService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Storage dashboard and history pages.
 */
class DashboardController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The statistics service.
   *
   * @var \Drupal\storage_manager\Service\StatisticsService
   */
  protected $statisticsService;

  /**
   * Config factory for storage manager settings.
   */
  protected ConfigFactoryInterface $storageConfigFactory;

  /**
   * Stripe helper service when mh_stripe is enabled.
   */
  protected ?StripeHelper $stripeHelper;

  /**
   * Constructs a new DashboardController object.
   *
   * @param \Drupal\storage_manager\Service\StatisticsService $statistics_service
   *   The statistics service.
   */
  public function __construct(StatisticsService $statistics_service, ConfigFactoryInterface $configFactory, ?StripeHelper $stripeHelper = NULL) {
    $this->statisticsService = $statistics_service;
    $this->storageConfigFactory = $configFactory;
    $this->stripeHelper = $stripeHelper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $stripeHelper = NULL;
    if ($container->has('mh_stripe.helper')) {
      $stripeHelper = $container->get('mh_stripe.helper');
    }

    return new static(
      $container->get('storage_manager.statistics_service'),
      $container->get('config.factory'),
      $stripeHelper,
    );
  }

  protected function buildStatistics(array $stats): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['storage-manager-statistics']],
    ];

    // Overall stats.
    $build['overall'] = [
      '#type' => 'details',
      '#title' => $this->t('Overall Statistics'),
      '#open' => TRUE,
    ];
    $build['overall']['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Metric'), $this->t('Value')],
      '#rows' => [
        [$this->t('Total units'), $stats['overall']['total_units']],
        [$this->t('Occupied units'), $stats['overall']['occupied_units']],
        [$this->t('Vacant units'), $stats['overall']['vacant_units']],
        [$this->t('Vacancy rate'), sprintf('%.2f%%', $stats['overall']['vacancy_rate'])],
        [$this->t('Billed value (monthly)'), '$' . number_format($stats['overall']['billed_value'], 2)],
        [$this->t('Complimentary value (monthly)'), '$' . number_format($stats['overall']['complimentary_value'], 2)],
        [$this->t('Potential value from vacant units (monthly)'), '$' . number_format($stats['overall']['potential_value'], 2)],
      ],
    ];

    // By type.
    $build['by_type'] = [
      '#type' => 'details',
      '#title' => $this->t('By Type'),
    ];
    $type_rows = [];
    foreach ($stats['by_type'] as $name => $data) {
      $type_rows[] = [
        $name,
        $data['total_units'],
        $data['occupied_units'],
        sprintf('%.2f%%', $data['vacancy_rate']),
        '$' . number_format($data['billed_value'], 2),
        '$' . number_format($data['complimentary_value'], 2),
        '$' . number_format($data['potential_value'], 2),
        '$' . number_format($data['total_inventory_value'], 2),
      ];
    }
    $build['by_type']['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Type'), $this->t('Total'), $this->t('Occupied'), $this->t('Vacancy Rate'), $this->t('Billed Value'), $this->t('Complimentary Value'), $this->t('Potential Value'), $this->t('Total Inventory Value')],
      '#rows' => $type_rows,
    ];

    // By area.
    $build['by_area'] = [
      '#type' => 'details',
      '#title' => $this->t('By Area'),
    ];
    $area_rows = [];
    foreach ($stats['by_area'] as $name => $data) {
      $area_rows[] = [
        $name,
        $data['total_units'],
        $data['occupied_units'],
        sprintf('%.2f%%', $data['vacancy_rate']),
        '$' . number_format($data['billed_value'], 2),
        '$' . number_format($data['complimentary_value'], 2),
        '$' . number_format($data['potential_value'], 2),
        '$' . number_format($data['total_inventory_value'], 2),
      ];
    }
    $build['by_area']['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Area'), $this->t('Total'), $this->t('Occupied'), $this->t('Vacancy Rate'), $this->t('Billed Value'), $this->t('Complimentary Value'), $this->t('Potential Value'), $this->t('Total Inventory Value')],
      '#rows' => $area_rows,
    ];

    // Violations.
    $build['violations'] = [
      '#type' => 'details',
      '#title' => $this->t('Violations'),
    ];
    $build['violations']['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Metric'), $this->t('Value')],
      '#rows' => [
        [$this->t('Active violations'), $stats['violations']['active_violations']],
        [$this->t('Total accrued charges'), '$' . number_format($stats['violations']['total_accrued'], 2)],
      ],
    ];

    return $build;
  }

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

    $stripeEnabled = $this->stripeHelper !== NULL
      && (bool) $this->storageConfigFactory->get('storage_manager.settings')->get('stripe.enable_billing');
    if ($stripeEnabled) {
      $build['stripe_notice'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--info']],
        'content' => [
          '#markup' => $this->t('Stripe billing is enabled. Storage assignments sync to Stripe automatically; use the assignment operations to open the linked customer or subscription when follow-up is needed. Billing status legend: "Active" links to the current Stripe subscription, "Customer" links to the member\'s Stripe customer when no storage subscription exists yet, and "Not linked" means the assignment has not been synchronized to Stripe.'),
        ],
      ];
    }

    $unit_storage = $this->entityTypeManager()->getStorage('storage_unit');
    $unit_ids = $unit_storage->getQuery()->accessCheck(TRUE)->execute();
    $units = $unit_storage->loadMultiple($unit_ids);

    $assignment_storage = $this->entityTypeManager()->getStorage('storage_assignment');
    $field_manager = \Drupal::service('entity_field.manager');
    $assignment_field_defs = $field_manager->getFieldDefinitions('storage_assignment', 'storage_assignment');
    $has_status = isset($assignment_field_defs['field_storage_assignment_status']);

    $violation_manager = \Drupal::service('storage_manager.violation_manager');
    $rows = [];
    $customerFieldName = $this->stripeHelper?->customerFieldName() ?? 'field_stripe_customer_id';

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
      $billing_status = $this->t('—');
      $customer_id = '';
      $subscription_id = '';

      if ($assignment) {
        $member_entity = $assignment->get('field_storage_user')->entity;
        if ($member_entity) {
          $member = Link::fromTextAndUrl(
            $member_entity->label(),
            Url::fromRoute('entity.user.canonical', ['user' => $member_entity->id()])
          )->toString();
        }

        if ($assignment->hasField('field_storage_complimentary') && (bool) $assignment->get('field_storage_complimentary')->value) {
          $monthly_cost = $this->t('Complimentary');
        }

        $subscription_id = $assignment->hasField('field_storage_stripe_sub_id')
          ? (string) ($assignment->get('field_storage_stripe_sub_id')->value ?? '')
          : '';
        $subscription_status = $assignment->hasField('field_storage_stripe_status')
          ? (string) ($assignment->get('field_storage_stripe_status')->value ?? '')
          : '';
        if ($subscription_status !== '') {
          $status_label = ucfirst(str_replace('_', ' ', $subscription_status));
        }
        elseif ($subscription_id !== '') {
          $status_label = $this->t('Active');
        }
        else {
          $status_label = $this->t('Not linked');
        }

        if ($stripeEnabled) {
          if ($member_entity?->hasField($customerFieldName)) {
            $customer_id = $member_entity->get($customerFieldName)->value ?? '';
          }

          if ($subscription_id) {
            $billing_status = Link::fromTextAndUrl(
              $status_label,
              Url::fromUri($this->stripeHelper->subscriptionDashboardUrl($subscription_id), [
                'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
              ])
            )->toString();
          }
          elseif ($customer_id) {
            $billing_status = Link::fromTextAndUrl(
              $this->t('Customer'),
              Url::fromUri($this->stripeHelper->customerDashboardUrl((string) $customer_id), [
                'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
              ])
            )->toString();
          }
          else {
            $billing_status = $status_label;
          }
        }
        else {
          $billing_status = $this->t('—');
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
      if ($assignment) {
        // Occupied unit.
        $ops['release'] = [
          'title' => $this->t('Release Unit'),
          'url' => Url::fromRoute('storage_manager.unit_release', ['storage_unit' => $unit->id()], [
            'query' => ['destination' => '/admin/storage'],
          ]),
        ];
        $edit_label = $active_violation
          ? $this->t('Resolve Violation')
          : $this->t('Edit Assignment');
        $ops['edit_assignment'] = [
          'title' => $edit_label,
          'url' => Url::fromRoute('storage_manager.assignment_edit', ['storage_assignment' => $assignment->id()]),
        ];
        if (!$active_violation) {
          $ops['add_violation'] = [
            'title' => $this->t('Add Violation'),
            'url' => Url::fromRoute('storage_manager.start_violation', ['storage_assignment' => $assignment->id()]),
          ];
        }
      }
      else {
        // Vacant unit.
        $ops['assign'] = [
          'title' => $this->t('Assign Unit'),
          'url' => Url::fromRoute('storage_manager.unit_assign', ['storage_unit' => $unit->id()], [
            'query' => ['destination' => '/admin/storage'],
          ]),
        ];
      }
      $ops['edit_unit'] = [
        'title' => $this->t('Edit Unit'),
        'url' => Url::fromRoute('entity.storage_unit.edit_form', ['storage_unit' => $unit->id()]),
      ];

      if ($assignment && $stripeEnabled) {
        if (!empty($customer_id)) {
          $ops['stripe_customer'] = [
            'title' => $this->t('Open Stripe Customer'),
            'url' => Url::fromUri($this->stripeHelper->customerDashboardUrl((string) $customer_id), [
              'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
            ]),
            'weight' => 55,
          ];
        }
        if (!empty($subscription_id)) {
          $ops['stripe_subscription'] = [
            'title' => $this->t('Open Stripe Subscription'),
            'url' => Url::fromUri($this->stripeHelper->subscriptionDashboardUrl($subscription_id), [
              'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
            ]),
            'weight' => 56,
          ];
        }
      }

      $operations_render = [
        '#type' => 'dropbutton',
        '#links' => $ops,
      ];

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

      $row = [
        $unit_id,
        $area,
        $type,
        $monthly_cost,
        ['data' => $status_render],
        $violation_summary,
        $member,
        ['data' => $operations_render],
      ];
      if ($stripeEnabled) {
        array_splice($row, 4, 0, [$billing_status]);
      }
      $rows[] = $row;
    }

    $header = [
      $this->buildSortableHeader('unit', $this->t('Unit ID'), $current_sort),
      $this->buildSortableHeader('area', $this->t('Area'), $current_sort),
      $this->buildSortableHeader('type', $this->t('Type'), $current_sort),
      $this->buildSortableHeader('cost', $this->t('Monthly Cost'), $current_sort),
    ];
    if ($stripeEnabled) {
      $header[] = $this->buildSortableHeader('billing', $this->t('Billing'), $current_sort);
    }
    $header = array_merge($header, [
      $this->buildSortableHeader('status', $this->t('Status'), $current_sort),
      $this->buildSortableHeader('violation', $this->t('Violation'), $current_sort),
      $this->buildSortableHeader('member', $this->t('Member'), $current_sort),
      $this->t('Operations'),
    ]);

    if ($current_sort['field']) {
      $rows = $this->sortRows($rows, $current_sort['field'], $current_sort['direction']);
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No storage units found.'),
    ];

    $stats = $this->statisticsService->getStatistics();
    $build['statistics'] = $this->buildStatistics($stats);

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

      if (!$unit) {
        $this->getLogger('storage_manager')->warning('Storage assignment @id references a non-existent storage unit.', ['@id' => $assignment->id()]);
        continue;
      }
      if (!$user) {
        $this->getLogger('storage_manager')->warning('Storage assignment @id references a non-existent user.', ['@id' => $assignment->id()]);
        continue;
      }

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
        $latest = $violations[0] ?? NULL;
        if ($latest) {
          $total_due = $latest->get('field_storage_vi_total')->value;
          $violation_label = $total_due !== NULL && $total_due !== ''
            ? $this->t('Resolved · $@amount', ['@amount' => number_format((float) $total_due, 2)])
            : $this->t('Resolved');
        }
      }

      $rows[] = [
        $unit?->get('field_storage_unit_id')->value ?? '—',
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
    if ($this->storageConfigFactory->get('storage_manager.settings')->get('stripe.enable_billing')) {
      $index_map['billing'] = 4;
      $index_map['status'] = 5;
      $index_map['violation'] = 6;
      $index_map['member'] = 7;
    }
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

    $config = $this->config('storage_manager.settings');
    $stripe_enabled = (bool) $config->get('stripe.enable_billing');
    if ($stripe_enabled) {
      $sections['member']['links'][] = [
        'title' => $this->t('Stripe Billing Portal'),
        'url' => Url::fromRoute('storage_manager_billing.portal'),
      ];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['storage-manager-hub']],
    ];

    foreach ($sections as $key => $section) {
      $build[$key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['storage-manager-hub__section']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $section['heading'],
          '#attributes' => ['class' => ['storage-manager-hub__heading']],
        ],
        'links' => [
          '#theme' => 'links',
          '#attributes' => ['class' => ['storage-manager-links']],
          '#links' => $section['links'],
        ],
      ];
    }

    return $build;
  }
}

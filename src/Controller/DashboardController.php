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

    // Load units and build the table.
    $u_storage = $this->entityTypeManager()->getStorage('storage_unit');
    $ids = $u_storage->getQuery()->accessCheck(FALSE)->execute();
    $units = $u_storage->loadMultiple($ids);

    $rows = [];
    foreach ($units as $unit) {
      $status = $unit->get('field_storage_status')->value;
      $name = $unit->label();

      $assign_link = Link::fromTextAndUrl(
        $this->t('Assign'),
        Url::fromRoute('storage_manager.assign_form', ['unit' => $unit->id()])
      )->toString();

      $release_link = Link::fromTextAndUrl(
        $this->t('Release'),
        Url::fromRoute('storage_manager.release_form', ['unit' => $unit->id()])
      )->toString();

      // Show “Release” only if occupied, else show “Assign”.
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
      $user = $a->get('field_assigned_user')->entity;
      $start = $a->get('field_start')->value ? date('Y-m-d', (int) $a->get('field_start')->value) : '—';
      $end = $a->get('field_end')->value ? date('Y-m-d', (int) $a->get('field_end')->value) : '—';
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

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
  public function view() {
    $build = [];

    // Intro / how it works.
    $intro = <<<HTML
<p><strong>Storage Manager</strong> lets you assign members to storage units.</p>
<ul>
  <li><strong>Areas</strong> = physical zones (e.g., Basement Cages, Craft Room Lockers).</li>
  <li><strong>Types</strong> = unit styles/sizes (e.g., Cage, Locker, Shelf).</li>
  <li><strong>Unit ID</strong> is the visible identifier we use on labels and in the UI.</li>
</ul>
HTML;
    $build['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['storage-manager-intro']],
      'text' => ['#markup' => Markup::create($intro)],
      'history_link' => Link::fromTextAndUrl(
        $this->t('View Assignment History'),
        Url::fromRoute('storage_manager.history')
      )->toRenderable(),
      '#prefix' => '<div class="mb-4">',
      '#suffix' => '</div>',
    ];

    // Load units.
    $unit_storage = $this->entityTypeManager()->getStorage('storage_unit');
    $units = $unit_storage->loadMultiple();

    $rows = [];
    foreach ($units as $unit) {
      // Show Unit ID (field) instead of title().
      $unit_id = $unit->get('field_storage_unit_id')->value ?: $this->t('—');

      // Build Edit link for the unit.
      $unit_edit = Link::fromTextAndUrl(
        $this->t('Edit Unit'),
        Url::fromRoute('entity.storage_unit.edit_form', ['storage_unit' => $unit->id()])
      )->toString();

      // Current assignment, if any.
      $current_assignment = $unit->get('field_current_assignment')->entity ?? NULL;
      $assignment_text = $this->t('Available');
      $assignment_ops = '';

      if ($current_assignment) {
        $assignee = $current_assignment->get('field_assigned_user')->entity;
        $assignee_name = $assignee ? $assignee->label() : $this->t('Unknown user');
        $assignment_text = $this->t('Assigned to @name', ['@name' => $assignee_name]);

        // “Edit assignment” uses the assignment's entity edit route.
        $assignment_ops = Link::fromTextAndUrl(
          $this->t('Edit Assignment'),
          Url::fromRoute('entity.storage_assignment.edit_form', ['storage_assignment' => $current_assignment->id()])
        )->toString();
      }

      $rows[] = [
        $unit_id,
        $unit->get('field_storage_area')->entity?->label() ?? $this->t('—'),
        $unit->get('field_storage_type')->entity?->label() ?? $this->t('—'),
        ['data' => ['#markup' => $assignment_text]],
        ['data' => ['#markup' => $unit_edit . ($assignment_ops ? ' | ' . $assignment_ops : '')]],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Unit ID'),
        $this->t('Area'),
        $this->t('Type'),
        $this->t('Status'),
        $this->t('Actions'),
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

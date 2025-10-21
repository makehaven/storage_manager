<?php

namespace Drupal\storage_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the member-facing storage dashboard.
 */
class MemberStorageController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $storageEntityTypeManager,
    private readonly AccountProxyInterface $storageCurrentUser,
    private readonly ConfigFactoryInterface $storageConfigFactory
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('config.factory'),
    );
  }

  /**
   * Renders the member storage dashboard.
   */
  public function page(): array {
    $account_id = (int) $this->storageCurrentUser->id();
    $build = [];

    $shortcuts = $this->buildShortcutLinks();
    if ($shortcuts) {
      $build['shortcuts'] = $shortcuts;
    }

    $storage = $this->storageEntityTypeManager->getStorage('storage_assignment');
    $ids = $storage->getQuery()
      ->condition('field_storage_user', $account_id)
      ->condition('field_storage_assignment_status', 'active')
      ->accessCheck(FALSE)
      ->execute();

    if (!$ids) {
      $build['empty'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--status']],
        'content' => ['#markup' => $this->t('You do not currently have any storage assigned.')],
      ];
      return $build;
    }

    $assignments = $storage->loadMultiple($ids);

    $rows = [];
    foreach ($assignments as $assignment) {
      $unit = $assignment->get('field_storage_unit')->entity;
      $unit_label = $unit?->get('field_storage_unit_id')->value ?: $this->t('Unit @id', ['@id' => $unit?->id()]);
      $area = $unit?->get('field_storage_area')->entity?->label() ?? $this->t('Unknown');
      $type = $unit?->get('field_storage_type')->entity?->label() ?? $this->t('Unknown');
      $start_date = $assignment->get('field_storage_start_date')->value ? date('Y-m-d', strtotime($assignment->get('field_storage_start_date')->value)) : $this->t('Not recorded');
      $monthly = $assignment->get('field_storage_price_snapshot')->value ?? ($unit?->get('field_storage_type')->entity?->get('field_monthly_price')->value ?? NULL);
      $is_complimentary = $assignment->hasField('field_storage_complimentary') && (bool) $assignment->get('field_storage_complimentary')->value;
      if ($is_complimentary) {
        $monthly_text = $this->t('Complimentary');
      }
      else {
        $monthly_text = $monthly !== NULL && $monthly !== ''
          ? '$' . number_format((float) $monthly, 2)
          : $this->t('N/A');
      }

      $rows[] = [
        $unit_label,
        $area,
        $type,
        $start_date,
        $monthly_text,
        Link::fromTextAndUrl(
          $this->t('Release storage'),
          Url::fromRoute('storage_manager.user_release', ['storage_assignment' => $assignment->id()])
        )->toString(),
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Unit'),
        $this->t('Area'),
        $this->t('Type'),
        $this->t('Start date'),
        $this->t('Monthly cost'),
        $this->t('Actions'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No active storage assignments found.'),
    ];

    $release_message = $this->storageConfigFactory->get('storage_manager.settings')->get('release_confirmation_message');
    if ($release_message) {
      $build['release_notice'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--status', 'mt-4']],
        'content' => ['#markup' => $this->t('Release reminder: @text', ['@text' => $release_message])],
      ];
    }

    return $build;
  }

  /**
   * Builds quick-access shortcut links for members and admins.
   */
  protected function buildShortcutLinks(): ?array {
    $member_links = [];
    if ($this->storageCurrentUser->hasPermission('claim storage unit')) {
      $member_links[] = [
        'title' => $this->t('Claim available storage'),
        'url' => Url::fromRoute('storage_manager.claim'),
      ];
    }

    $billing_links = [];
    if ($this->storageCurrentUser->hasPermission('claim storage unit')) {
      $billing_links[] = [
        'title' => $this->t('View Storage Invoices'),
        'url' => Url::fromRoute('storage_manager_billing.portal'),
      ];
    }

    $admin_links = [];
    if ($this->storageCurrentUser->hasPermission('manage storage')) {
      $admin_links[] = [
        'title' => $this->t('Storage manager dashboard'),
        'url' => Url::fromRoute('storage_manager.dashboard'),
      ];
      $admin_links[] = [
        'title' => $this->t('Assignment & violation history'),
        'url' => Url::fromRoute('storage_manager.history'),
      ];
      $admin_links[] = [
        'title' => $this->t('Storage settings'),
        'url' => Url::fromRoute('storage_manager.settings'),
      ];
      $admin_links[] = [
        'title' => $this->t('Manage storage areas'),
        'url' => Url::fromRoute('entity.taxonomy_vocabulary.overview_form', ['taxonomy_vocabulary' => 'storage_area']),
      ];
      $admin_links[] = [
        'title' => $this->t('Manage storage types'),
        'url' => Url::fromRoute('entity.taxonomy_vocabulary.overview_form', ['taxonomy_vocabulary' => 'storage_type']),
      ];
      $admin_links[] = [
        'title' => $this->t('Open member claim page'),
        'url' => Url::fromRoute('storage_manager.claim'),
        'attributes' => ['class' => ['storage-manager-link-highlight']],
      ];
    }

    if (!$member_links && !$admin_links && !$billing_links) {
      return NULL;
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['storage-manager-shortcuts']],
      '#cache' => [
        'contexts' => ['user.permissions'],
      ],
    ];

    if ($member_links) {
      $build['member_heading'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Quick actions'),
      ];
      $build['member_links'] = [
        '#theme' => 'links',
        '#attributes' => ['class' => ['storage-manager-links', 'storage-manager-links--member']],
        '#links' => $member_links,
      ];
    }

    if ($billing_links) {
      $build['billing_heading'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Billing'),
      ];
      $build['billing_links'] = [
        '#theme' => 'links',
        '#attributes' => ['class' => ['storage-manager-links', 'storage-manager-links--billing']],
        '#links' => $billing_links,
      ];
    }

    if ($admin_links) {
      $build['admin_heading'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Admin tools'),
      ];
      $build['admin_links'] = [
        '#theme' => 'links',
        '#attributes' => ['class' => ['storage-manager-links', 'storage-manager-links--admin']],
        '#links' => $admin_links,
      ];
    }

    return $build;
  }
}

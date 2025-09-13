<?php

use Drupal\taxonomy\Entity\Term;

/**
 * Seed common storage areas (safe to re-run).
 */
function storage_manager_post_update_seed_storage_areas(&$sandbox) {
  $vid = 'storage_area';
  $names = ['Woodshop', 'Metalshop', 'Studio', 'CNC Room'];
  foreach ($names as $name) {
    $exists = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->getQuery()
      ->condition('vid', $vid)
      ->condition('name', $name)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    if (!$exists) {
      Term::create(['vid' => $vid, 'name' => $name])->save();
    }
  }
}

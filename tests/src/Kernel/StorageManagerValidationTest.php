<?php

namespace Drupal\Tests\storage_manager\Kernel;

use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\eck\Entity\EckEntity;

/**
 * Tests the validation constraints for storage manager entities.
 *
 * @group storage_manager
 */
class StorageManagerValidationTest extends EntityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'eck',
    'field',
    'link',
    'options',
    'storage_manager',
    'taxonomy',
    'text',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['storage_manager']);
    $this->installEntitySchema('storage_assignment');
    $this->installEntitySchema('storage_unit');
  }

  /**
   * Tests that the storage unit ID is unique.
   */
  public function testUniqueStorageUnitId() {
    $unit1 = EckEntity::create([
      'eck_entity_type' => 'storage_unit',
      'type' => 'storage_unit',
      'field_storage_unit_id' => ['value' => 'A1'],
    ]);
    $violations = $unit1->validate();
    $this->assertCount(0, $violations, 'No violations found for the first unit.');
    $unit1->save();

    $unit2 = EckEntity::create([
      'eck_entity_type' => 'storage_unit',
      'type' => 'storage_unit',
      'field_storage_unit_id' => ['value' => 'A1'],
    ]);
    $violations = $unit2->validate();
    $this->assertCount(1, $violations, 'A violation is found for the second unit with the same ID.');
    $this->assertEquals('The Unit ID must be unique.', $violations[0]->getMessage());
  }

}
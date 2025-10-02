<?php

namespace Drupal\storage_manager\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures that a storage unit ID is unique.
 *
 * @Constraint(
 *   id = "UniqueStorageUnitId",
 *   label = @Translation("Unique Storage Unit ID", context = "Validation"),
 *   type = "string"
 * )
 */
class UniqueStorageUnitIdConstraint extends Constraint {

  /**
   * The message that will be shown if the value is not unique.
   *
   * @var string
   */
  public $notUnique = 'The Unit ID must be unique.';

}
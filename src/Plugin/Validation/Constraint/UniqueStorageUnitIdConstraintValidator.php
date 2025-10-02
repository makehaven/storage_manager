<?php

namespace Drupal\storage_manager\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the UniqueStorageUnitId constraint.
 */
class UniqueStorageUnitIdConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {
    if (NULL === $value || '' === $value) {
      return;
    }

    $field_value = $value->value;
    $entity = $value->getEntity();

    $query = \Drupal::entityQuery('storage_unit')
      ->condition('field_storage_unit_id', $field_value)
      ->accessCheck(FALSE);

    if (!$entity->isNew()) {
      $query->condition('id', $entity->id(), '<>');
    }

    $ids = $query->execute();

    if (!empty($ids)) {
      $this->context->addViolation($constraint->notUnique);
    }
  }

}
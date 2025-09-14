<?php

namespace Drupal\storage_manager\EventSubscriber;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeEvents;
use Drupal\Core\Entity\Event\EntityPresaveEvent;
use Drupal\storage_manager\Service\AssignmentGuard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AssignmentSubscriber implements EventSubscriberInterface {

  public function __construct(private AssignmentGuard $guard) {}

  public static function getSubscribedEvents(): array {
    return [
      EntityTypeEvents::PRESAVE => 'onEntityPreSave',
    ];
  }

  public function onEntityPreSave(EntityPresaveEvent $event): void {
    $entity = $event->getEntity();
    if ($entity->getEntityTypeId() !== 'storage_assignment') {
      return;
    }
    $status = $entity->get('field_storage_assignment_status')->value;
    if ($status !== 'Active') {
      return;
    }
    $unit = $entity->get('field_storage_unit')->entity;
    if (!$unit) {
      return;
    }
    // If some *other* Active assignment exists for this unit, block.
    if ($this->guard->unitHasActiveAssignment($unit->id()) && $entity->isNew()) {
      throw new \RuntimeException('This storage unit already has an active assignment.');
    }
  }
}

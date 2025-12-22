<?php

namespace Drupal\greek_holidays;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Deletes holiday entities before uninstall validation completes.
 */
class GreekHolidaysUninstallValidator implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs the validator.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function validate($module): array {
    if ($module !== 'greek_holidays') {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('holiday');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->execute();

    if ($ids) {
      $storage->delete($storage->loadMultiple($ids));
    }

    return [];
  }

}

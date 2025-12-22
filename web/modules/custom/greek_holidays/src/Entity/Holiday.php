<?php

namespace Drupal\greek_holidays\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityTypeInterface;

/**
 * Defines the Holiday entity.
 *
 * @ContentEntityType(
 *   id = "holiday",
 *   label = @Translation("Holiday"),
 *   label_collection = @Translation("Holidays"),
 *   handlers = {
 *     "access" = "Drupal\greek_holidays\HolidayAccessControlHandler",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "add" = "Drupal\greek_holidays\Form\HolidayForm",
 *       "edit" = "Drupal\greek_holidays\Form\HolidayForm",
 *       "delete" = "Drupal\greek_holidays\Form\HolidayDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "holiday",
 *   admin_permission = "administer holiday entities",
 *   links = {
 *     "add-form" = "/greek_holidays/add",
 *     "edit-form" = "/greek_holidays/{holiday}/edit",
 *     "delete-form" = "/greek_holidays/{holiday}/delete",
 *     "canonical" = "/greek_holidays/{holiday}"
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "description"
 *   }
 * )
 */
class Holiday extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Date'))
      ->setDescription(t('Holiday date.'))
      ->setSetting('datetime_type', 'date')
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'type' => 'datetime_default',
        'weight' => 0,
      ]);

    $fields['description'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Description'))
      ->setDescription(t('Holiday description.'))
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 1,
      ])
      ->setDisplayOptions('view', [
        'type' => 'text_default',
        'weight' => 1,
      ]);

    $fields['created_by'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Created by'))
      ->setDescription(t('How the holiday was created.'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          'manual' => 'Manual',
          'api' => 'API',
        ],
      ])
      ->setDefaultValue('manual')
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 2,
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
        'weight' => 2,
      ]);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}

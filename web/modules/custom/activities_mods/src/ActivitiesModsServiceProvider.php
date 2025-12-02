<?php

namespace Drupal\activities_mods;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Alters services for the Activities module.
 */
class ActivitiesModsServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('activities.logger')) {
      $definition = $container->getDefinition('activities.logger');
      $definition->setClass(ActivitiesLogger::class);
    }
  }

}

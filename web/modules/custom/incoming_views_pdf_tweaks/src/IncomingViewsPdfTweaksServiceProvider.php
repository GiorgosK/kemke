<?php

namespace Drupal\incoming_views_pdf_tweaks;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Alters XLS serialization encoder services.
 */
class IncomingViewsPdfTweaksServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('xls_serialization.encoder.xls')) {
      $container->getDefinition('xls_serialization.encoder.xls')
        ->setClass(Encoder\BorderedXls::class);
    }

    if ($container->hasDefinition('xls_serialization.encoder.xlsx')) {
      $container->getDefinition('xls_serialization.encoder.xlsx')
        ->setClass(Encoder\BorderedXlsx::class);
    }
  }

}


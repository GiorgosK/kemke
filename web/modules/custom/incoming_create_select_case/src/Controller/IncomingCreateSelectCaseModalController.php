<?php

namespace Drupal\incoming_create_select_case\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds modal forms for create/select case.
 */
class IncomingCreateSelectCaseModalController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(protected RequestStack $requestStack) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('request_stack'),
    );
  }

  /**
   * Builds the taxonomy term add form for a bundle.
   */
  public function build(Request $request, string $bundle): array {
    $entity = $this->entityTypeManager()
      ->getStorage('taxonomy_term')
      ->create(['vid' => $bundle]);

    $form = $this->entityTypeManager()
      ->getFormObject('taxonomy_term', 'default')
      ->setEntity($entity);

    $form = $this->formBuilder()->getForm($form);
    $form['#cache']['max-age'] = 0;

    return $form;
  }

  /**
   * Access check for modal requests.
   */
  public function access(AccountInterface $account, string $bundle): AccessResult {
    $request = $this->requestStack->getCurrentRequest();
    $wrapper_format = $request->query->get('_wrapper_format');
    $drupal_ajax = $request->request->get('_drupal_ajax');
    if ($wrapper_format !== 'drupal_modal' && $drupal_ajax != 1) {
      return AccessResult::forbidden();
    }
    return AccessResult::allowed();
  }

}

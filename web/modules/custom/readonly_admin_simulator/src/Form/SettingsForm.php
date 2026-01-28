<?php

declare(strict_types=1);

namespace Drupal\readonly_admin_simulator\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\RoleStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for Read-only Admin Simulator.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * Role storage.
   */
  protected RoleStorageInterface $roleStorage;

  public static function create(ContainerInterface $container): self {
    $instance = parent::create($container);
    $instance->roleStorage = $container->get('entity_type.manager')->getStorage('user_role');
    return $instance;
  }

  public function getFormId(): string {
    return 'readonly_admin_simulator_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return ['readonly_admin_simulator.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('readonly_admin_simulator.settings');

    $roles = $this->roleStorage->loadMultiple();
    $options = [];
    foreach ($roles as $role) {
      /** @var \Drupal\user\RoleInterface $role */
      $options[$role->id()] = $role->label() . ' (' . $role->id() . ')';
    }

    $form['simulated_role'] = [
      '#type' => 'select',
      '#title' => $this->t('Role to simulate (permissions source)'),
      '#description' => $this->t('Users with the read-only role will be treated as if they also have this role for access checks.'),
      '#options' => $options,
      '#default_value' => $config->get('simulated_role') ?: 'kemke_admin',
      '#required' => TRUE,
    ];

    $form['readonly_role'] = [
      '#type' => 'select',
      '#title' => $this->t('Read-only role'),
      '#description' => $this->t('Users with this role will simulate the role above but will be prevented from saving/deleting/updating and submitting admin forms.'),
      '#options' => $options,
      '#default_value' => $config->get('readonly_role') ?: 'kemke_admin_readonly',
      '#required' => TRUE,
    ];

    $form['disable_admin_submits'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable submit buttons on admin forms (UX only)'),
      '#default_value' => (bool) $config->get('disable_admin_submits'),
      '#description' => $this->t('This is only cosmetic. Real protection is enforced server-side.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('readonly_admin_simulator.settings')
      ->set('simulated_role', (string) $form_state->getValue('simulated_role'))
      ->set('readonly_role', (string) $form_state->getValue('readonly_role'))
      ->set('disable_admin_submits', (bool) $form_state->getValue('disable_admin_submits'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}

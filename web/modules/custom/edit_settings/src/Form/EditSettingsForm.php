<?php

declare(strict_types=1);

namespace Drupal\edit_settings\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;

final class EditSettingsForm extends FormBase {

  public static function access(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf(in_array('administrator', $account->getRoles(), TRUE))
      ->addCacheContexts(['user.roles']);
  }

  public function getFormId(): string {
    return 'edit_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $files = $this->getEditableFiles();
    if ($files === []) {
      $form['message'] = [
        '#markup' => $this->t('No settings files were found under the sites directory.'),
      ];
      return $form;
    }

    $default_file = $form_state->get('selected_file');
    if (!is_string($default_file) || $default_file === '') {
      $default_file = $this->getPreferredDefaultFile(array_keys($files)) ?? (string) array_key_first($files);
    }

    $selected_file = (string) $form_state->getValue('selected_file', $default_file);
    if (!isset($files[$selected_file])) {
      $selected_file = (string) array_key_first($files);
    }
    $form_state->set('selected_file', $selected_file);

    $form['editor'] = [
      '#type' => 'container',
      '#prefix' => '<div id="edit-settings-editor">',
      '#suffix' => '</div>',
    ];

    $form['editor']['selected_file'] = [
      '#type' => 'select',
      '#title' => $this->t('Settings file'),
      '#options' => $files,
      '#default_value' => $selected_file,
      '#ajax' => [
        'callback' => '::refreshEditor',
        'wrapper' => 'edit-settings-editor',
      ],
    ];

    $filename_value = (string) $form_state->getValue('target_filename', basename($selected_file));
    $contents_value = $form_state->getValue('file_contents');
    if (!is_string($contents_value)) {
      $contents_value = @file_get_contents($selected_file);
      if (!is_string($contents_value)) {
        $contents_value = '';
      }
    }

    $form['editor']['target_filename'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Filename'),
      '#default_value' => $filename_value,
      '#required' => TRUE,
      '#description' => $this->t('Rename the selected file within the same directory.'),
    ];

    $form['editor']['file_contents'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Contents'),
      '#default_value' => $contents_value,
      '#rows' => 28,
      '#required' => TRUE,
      '#attributes' => [
        'style' => 'font-family: monospace;',
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function refreshEditor(array &$form, FormStateInterface $form_state): array {
    $selected_file = (string) $form_state->getValue('selected_file', '');
    $form_state->set('selected_file', $selected_file);
    $form_state->setValue('target_filename', basename($selected_file));
    $contents = @file_get_contents($selected_file);
    $form_state->setValue('file_contents', is_string($contents) ? $contents : '');
    $form_state->setRebuild();
    return $form['editor'];
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $files = $this->getEditableFiles();
    $selected_file = (string) $form_state->getValue('selected_file', '');
    if (!isset($files[$selected_file])) {
      $form_state->setErrorByName('selected_file', $this->t('The selected settings file is not editable.'));
      return;
    }

    $target_filename = trim((string) $form_state->getValue('target_filename', ''));
    if ($target_filename === '') {
      $form_state->setErrorByName('target_filename', $this->t('Filename is required.'));
      return;
    }

    if (str_contains($target_filename, '/') || str_contains($target_filename, '\\')) {
      $form_state->setErrorByName('target_filename', $this->t('Filename must not include path separators.'));
    }

    if (!preg_match('/^settings(?:\.[A-Za-z0-9_.-]+)?(?:\.php)?$/', $target_filename)) {
      $form_state->setErrorByName('target_filename', $this->t('Filename must start with settings and remain a settings file.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $selected_file = (string) $form_state->getValue('selected_file');
    $target_filename = trim((string) $form_state->getValue('target_filename'));
    $contents = (string) $form_state->getValue('file_contents');

    $directory = dirname($selected_file);
    $target_path = $directory . DIRECTORY_SEPARATOR . $target_filename;

    if ($target_path !== $selected_file && file_exists($target_path)) {
      $this->messenger()->addError($this->t('The target file already exists.'));
      return;
    }

    if ($target_path !== $selected_file && !@rename($selected_file, $target_path)) {
      $this->messenger()->addError($this->t('The file could not be renamed.'));
      return;
    }

    if (@file_put_contents($target_path, $contents) === FALSE) {
      $this->messenger()->addError($this->t('The file could not be saved.'));
      return;
    }

    $form_state->set('selected_file', $target_path);
    $form_state->setValue('selected_file', $target_path);
    $this->messenger()->addStatus($this->t('Saved settings file @file.', ['@file' => basename($target_path)]));
    $form_state->setRebuild();
  }

  /**
   * @return array<string, string>
   */
  private function getEditableFiles(): array {
    $sites_directory = DRUPAL_ROOT . '/sites';
    if (!is_dir($sites_directory)) {
      return [];
    }

    $paths = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($sites_directory, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
      if (!$file instanceof \SplFileInfo || !$file->isFile()) {
        continue;
      }

      $filename = $file->getFilename();
      if (
        $filename !== 'settings.php' &&
        !preg_match('/^settings\..+$/', $filename)
      ) {
        continue;
      }

      $real_path = $file->getRealPath();
      if (!is_string($real_path) || $real_path === '') {
        continue;
      }

      $relative = ltrim(str_replace(DRUPAL_ROOT . '/', '', $real_path), '/');
      $paths[$real_path] = $relative;
    }

    asort($paths, SORT_NATURAL);
    return $paths;
  }

  /**
   * @param array<int, string> $paths
   */
  private function getPreferredDefaultFile(array $paths): ?string {
    foreach ($paths as $path) {
      if (basename($path) === 'settings.local.php') {
        return $path;
      }
    }

    return NULL;
  }

}

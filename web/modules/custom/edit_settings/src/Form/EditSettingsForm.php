<?php

declare(strict_types=1);

namespace Drupal\edit_settings\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;

final class EditSettingsForm extends FormBase {

  private const EXTRA_EDITABLE_FILES = [
    '../private/gsis-pa/oauth-calls.log' => [
      'rename_pattern' => '/^oauth-calls\.log$/',
    ],
  ];

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
        '#markup' => $this->t('No editable files were found.'),
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

    if ($this->isSelectedFileAjaxRequest($form_state)) {
      $filename = basename($selected_file);
      $contents = $this->loadFileContents($selected_file);

      $form_state->setValue('target_filename', $filename);
      $form_state->setValue('file_contents', $contents);

      $user_input = $form_state->getUserInput();
      $user_input['target_filename'] = $filename;
      $user_input['file_contents'] = $contents;
      $form_state->setUserInput($user_input);
    }

    $form['editor'] = [
      '#type' => 'container',
      '#prefix' => '<div id="edit-settings-editor">',
      '#suffix' => '</div>',
    ];

    $form['editor']['selected_file'] = [
      '#type' => 'select',
      '#title' => $this->t('Editable file'),
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
      $contents_value = $this->loadFileContents($selected_file);
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
    return $form['editor'];
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $files = $this->getEditableFiles();
    $editable_configs = $this->getEditableFileConfigs();
    $selected_file = (string) $form_state->getValue('selected_file', '');
    if (!isset($files[$selected_file])) {
      $form_state->setErrorByName('selected_file', $this->t('The selected file is not editable.'));
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

    $rename_pattern = $editable_configs[$selected_file]['rename_pattern'] ?? NULL;
    if (!is_string($rename_pattern) || !preg_match($rename_pattern, $target_filename)) {
      $form_state->setErrorByName('target_filename', $this->t('Filename is not allowed for the selected file.'));
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
    $this->messenger()->addStatus($this->t('Saved file @file.', ['@file' => basename($target_path)]));
    $form_state->setRebuild();
  }

  /**
   * @return array<string, string>
   */
  private function getEditableFiles(): array {
    $configs = $this->getEditableFileConfigs();
    $paths = [];
    foreach ($configs as $path => $config) {
      $paths[$path] = $config['label'];
    }

    asort($paths, SORT_NATURAL);
    return $paths;
  }

  /**
   * @return array<string, array{label: string, rename_pattern: string}>
   */
  private function getEditableFileConfigs(): array {
    $sites_directory = DRUPAL_ROOT . '/sites';
    $paths = [];
    if (is_dir($sites_directory)) {
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
        $paths[$real_path] = [
          'label' => $relative,
          'rename_pattern' => '/^settings(?:\.[A-Za-z0-9_.-]+)?(?:\.php)?$/',
        ];
      }
    }

    foreach (self::EXTRA_EDITABLE_FILES as $relative_path => $config) {
      $absolute_path = DRUPAL_ROOT . '/' . ltrim($relative_path, '/');
      $real_path = realpath($absolute_path);
      if (!is_string($real_path) || $real_path === '' || !is_file($real_path)) {
        continue;
      }

      $paths[$real_path] = [
        'label' => $this->buildDisplayPath($real_path),
        'rename_pattern' => $config['rename_pattern'],
      ];
    }

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

  private function isSelectedFileAjaxRequest(FormStateInterface $form_state): bool {
    $trigger = $form_state->getTriggeringElement();
    return is_array($trigger) && ($trigger['#name'] ?? NULL) === 'selected_file';
  }

  private function loadFileContents(string $path): string {
    $contents = @file_get_contents($path);
    return is_string($contents) ? $contents : '';
  }

  private function buildDisplayPath(string $path): string {
    $project_root = dirname(DRUPAL_ROOT);
    if (str_starts_with($path, $project_root . '/')) {
      return ltrim(str_replace($project_root . '/', '', $path), '/');
    }

    return $path;
  }

}

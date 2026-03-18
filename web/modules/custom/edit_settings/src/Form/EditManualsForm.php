<?php

declare(strict_types=1);

namespace Drupal\edit_settings\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class EditManualsForm extends FormBase {

  public static function access(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf(in_array('administrator', $account->getRoles(), TRUE))
      ->addCacheContexts(['user.roles']);
  }

  public function getFormId(): string {
    return 'edit_manuals_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['enctype'] = 'multipart/form-data';

    $form['upload'] = [
      '#type' => 'details',
      '#title' => $this->t('Upload file'),
      '#open' => TRUE,
    ];

    $form['upload']['upload_file'] = [
      '#type' => 'file',
      '#title' => $this->t('File'),
    ];

    $form['upload']['actions'] = [
      '#type' => 'actions',
    ];
    $form['upload']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload'),
      '#name' => 'upload_manual',
      '#button_type' => 'primary',
      '#submit' => ['::submitForm'],
    ];

    $files = $this->getManualFiles();
    $form['files'] = [
      '#type' => 'details',
      '#title' => $this->t('Existing files'),
      '#open' => TRUE,
    ];

    if ($files === []) {
      $form['files']['empty'] = [
        '#markup' => $this->t('No files found in the manuals directory.'),
      ];
      return $form;
    }

    $form['files']['items'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Filename'),
        $this->t('Size'),
        $this->t('Operations'),
      ],
      '#empty' => $this->t('No files found in the manuals directory.'),
    ];

    foreach ($files as $path => $label) {
      $row_key = md5($path);
      $form['files']['items'][$row_key]['name'] = [
        '#markup' => $label,
      ];
      $form['files']['items'][$row_key]['size'] = [
        '#markup' => (string) ByteSizeMarkup::create((int) filesize($path)),
      ];
      $form['files']['items'][$row_key]['delete'] = [
        '#type' => 'submit',
        '#value' => $this->t('Delete'),
        '#name' => 'delete_' . md5($path),
        '#submit' => ['::deleteFileSubmit'],
        '#limit_validation_errors' => [],
        '#manual_path' => $path,
      ];
    }

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    if (!is_array($trigger) || ($trigger['#name'] ?? '') !== 'upload_manual') {
      return;
    }

    $upload = $this->getUploadedFile();
    if (!$upload instanceof UploadedFile) {
      $form_state->setErrorByName('upload_file', $this->t('Please choose a file to upload.'));
      return;
    }

    if (!$upload->isValid()) {
      $form_state->setErrorByName('upload_file', $this->t('The uploaded file is not valid.'));
      return;
    }

    $filename = $this->sanitizeFilename($upload->getClientOriginalName());
    if ($filename === '') {
      $form_state->setErrorByName('upload_file', $this->t('The uploaded file must have a valid filename.'));
      return;
    }

    if ($this->manualFileExists($filename)) {
      $form_state->setErrorByName('upload_file', $this->t('A file with that name already exists.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $directory = $this->getManualsDirectory();
    if (!is_dir($directory) && !@mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      $this->messenger()->addError($this->t('The manuals directory could not be created.'));
      return;
    }

    $upload = $this->getUploadedFile();
    if (!$upload instanceof UploadedFile || !$upload->isValid()) {
      $this->messenger()->addError($this->t('Please choose a valid file to upload.'));
      return;
    }

    $filename = $this->sanitizeFilename($upload->getClientOriginalName());
    if ($filename === '') {
      $this->messenger()->addError($this->t('The uploaded file must have a valid filename.'));
      return;
    }

    if ($this->manualFileExists($filename)) {
      $this->messenger()->addError($this->t('A file with that name already exists.'));
      return;
    }

    try {
      $upload->move($directory, $filename);
      $this->messenger()->addStatus($this->t('Uploaded @file.', ['@file' => $filename]));
    }
    catch (\Throwable) {
      $this->messenger()->addError($this->t('The file could not be uploaded.'));
    }

    $form_state->setRebuild();
  }

  public function deleteFileSubmit(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $path = is_array($trigger) ? (string) ($trigger['#manual_path'] ?? '') : '';
    $directory = $this->getManualsDirectory();
    $real_directory = realpath($directory);
    $real_path = realpath($path);

    if (!is_string($real_directory) || !is_string($real_path) || !str_starts_with($real_path, $real_directory . DIRECTORY_SEPARATOR)) {
      $this->messenger()->addError($this->t('The selected file is invalid.'));
      $form_state->setRebuild();
      return;
    }

    if (!is_file($real_path) || !@unlink($real_path)) {
      $this->messenger()->addError($this->t('The file could not be deleted.'));
      $form_state->setRebuild();
      return;
    }

    $this->messenger()->addStatus($this->t('Deleted @file.', ['@file' => basename($real_path)]));
    $form_state->setRebuild();
  }

  /**
   * @return array<string, string>
   */
  private function getManualFiles(): array {
    $directory = $this->getManualsDirectory();
    if (!is_dir($directory)) {
      return [];
    }

    $files = [];
    $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
    foreach ($iterator as $file) {
      if (!$file instanceof \SplFileInfo || !$file->isFile()) {
        continue;
      }

      $real_path = $file->getRealPath();
      if (!is_string($real_path) || $real_path === '') {
        continue;
      }

      $files[$real_path] = $file->getFilename();
    }

    asort($files, SORT_NATURAL);
    return $files;
  }

  private function getManualsDirectory(): string {
    return DRUPAL_ROOT . '/sites/default/files/manuals';
  }

  private function getUploadedFile(): ?UploadedFile {
    $files = $this->getRequest()->files->get('files');
    if (!is_array($files)) {
      return NULL;
    }

    $upload = $files['upload_file'] ?? NULL;
    return $upload instanceof UploadedFile ? $upload : NULL;
  }

  private function sanitizeFilename(string $filename): string {
    $filename = $this->normalizeFilename(trim(basename($filename)));
    $filename = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $filename);
    return is_string($filename) ? $this->normalizeFilename(trim($filename)) : '';
  }

  private function manualFileExists(string $filename): bool {
    $filename = $this->normalizeFilename($filename);
    $directory = $this->getManualsDirectory();
    if (!is_dir($directory)) {
      return FALSE;
    }

    clearstatcache(TRUE, $directory);
    $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
    foreach ($iterator as $file) {
      if (!$file instanceof \SplFileInfo || !$file->isFile()) {
        continue;
      }

      if ($this->normalizeFilename($file->getFilename()) === $filename) {
        return TRUE;
      }
    }

    return FALSE;
  }

  private function normalizeFilename(string $filename): string {
    if (class_exists(\Normalizer::class)) {
      $normalized = \Normalizer::normalize($filename, \Normalizer::FORM_C);
      if (is_string($normalized) && $normalized !== '') {
        return $normalized;
      }
    }

    return $filename;
  }

}

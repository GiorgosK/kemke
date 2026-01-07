<?php

declare(strict_types=1);

namespace Drupal\user_import\Commands;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for importing users from CSV/TSV files.
 */
final class UserImportCommands extends DrushCommands {

  private EntityStorageInterface $userStorage;

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private readonly TransliterationInterface $transliteration
  ) {
    parent::__construct();
    $this->userStorage = $entityTypeManager->getStorage('user');
  }

  /**
   * Import users from a CSV/TSV file.
   *
   * The file should include headers: field_serial_number, field_last_name,
   * field_first_name, role.
   *
   * @command user-import:csv
   * @aliases uic
   * @option delimiter Field delimiter (default: tab). Use "\\t" or "tab" for TSV.
   * @option dry-run Parse and report without saving users.
   * @option password Use the same password for all imported users.
   */
  public function import(string $path, array $options = ['delimiter' => 'tab', 'dry-run' => false, 'password' => '']): void {
    $delimiter = $this->normalizeDelimiter((string) ($options['delimiter'] ?? 'tab'));
    $dryRun = (bool) ($options['dry-run'] ?? false);
    $password = (string) ($options['password'] ?? '');

    if (!is_readable($path)) {
      $this->output()->writeln(sprintf('File not readable: %s', $path));
      return;
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
      $this->output()->writeln(sprintf('Unable to open file: %s', $path));
      return;
    }

    $header = fgetcsv($handle, 0, $delimiter);
    if ($header === false) {
      fclose($handle);
      $this->output()->writeln('Empty file or missing header row.');
      return;
    }

    $header = array_map('trim', $header);
    $required = ['field_serial_number', 'field_last_name', 'field_first_name', 'role'];
    $missing = array_diff($required, $header);
    if ($missing) {
      fclose($handle);
      $this->output()->writeln(sprintf('Missing required headers: %s', implode(', ', $missing)));
      return;
    }

    $created = 0;
    $skipped = 0;
    $rowNumber = 1;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
      $rowNumber++;
      if ($this->isEmptyRow($row)) {
        continue;
      }
      if (count($row) !== count($header)) {
        $this->output()->writeln(sprintf('Row %d: column count mismatch, skipping.', $rowNumber));
        $skipped++;
        continue;
      }

      $data = array_combine($header, $row);
      if ($data === false) {
        $this->output()->writeln(sprintf('Row %d: unable to parse, skipping.', $rowNumber));
        $skipped++;
        continue;
      }

      $serial = trim((string) ($data['field_serial_number'] ?? ''));
      $last = trim((string) ($data['field_last_name'] ?? ''));
      $first = trim((string) ($data['field_first_name'] ?? ''));
      $role = trim((string) ($data['role'] ?? ''));
      $email = trim((string) ($data['email'] ?? ''));

      if ($serial === '' || $last === '' || $first === '') {
        $this->output()->writeln(sprintf('Row %d: missing required values, skipping.', $rowNumber));
        $skipped++;
        continue;
      }

      $usernameBase = $this->buildUsername($last, $first);
      $username = $this->ensureUniqueUsername($usernameBase);

      if ($username === '') {
        $this->output()->writeln(sprintf('Row %d: unable to generate username, skipping.', $rowNumber));
        $skipped++;
        continue;
      }

      $account = $this->userStorage->loadByProperties(['name' => $username]);
      if ($account) {
        $this->output()->writeln(sprintf('Row %d: username "%s" already exists, skipping.', $rowNumber, $username));
        $skipped++;
        continue;
      }

      $mail = $email !== '' ? $email : sprintf('%s@kemke.com', $username);
      $roleEntity = $role !== '' ? Role::load($role) : null;
      if ($role !== '' && $roleEntity === null) {
        $this->output()->writeln(sprintf('Row %d: role "%s" not found, assigning none.', $rowNumber, $role));
      }

      if ($dryRun) {
        $this->output()->writeln(sprintf(
          'Row %d: would create "%s" (%s %s) with role "%s".',
          $rowNumber,
          $username,
          $last,
          $first,
          $role !== '' ? $role : 'none'
        ));
        $created++;
        continue;
      }

      /** @var \Drupal\user\UserInterface $user */
      $user = $this->userStorage->create([
        'name' => $username,
        'mail' => $mail,
        'status' => 1,
        'pass' => $password !== '' ? $password : null,
        'field_serial_number' => $serial,
        'field_last_name' => $last,
        'field_first_name' => $first,
      ]);

      if ($roleEntity !== null && $roleEntity->id() !== UserInterface::AUTHENTICATED_ROLE) {
        $user->addRole($roleEntity->id());
      }

      $user->save();
      $created++;
      $this->output()->writeln(sprintf('Row %d: created user "%s".', $rowNumber, $username));
    }

    fclose($handle);
    $this->output()->writeln(sprintf('Done. Created: %d. Skipped: %d.', $created, $skipped));
  }

  private function buildUsername(string $last, string $first): string {
    $raw = sprintf('%s_%s', $last, $first);
    $latin = $this->transliteration->transliterate($raw, 'en', '');
    $latin = strtolower($latin);
    $latin = preg_replace('/[^a-z0-9_]+/', '_', $latin);
    $latin = trim($latin ?? '', '_');
    return $latin ?? '';
  }

  private function ensureUniqueUsername(string $base): string {
    if ($base === '') {
      return '';
    }

    $candidate = $base;
    $suffix = 2;
    while ($this->userStorage->loadByProperties(['name' => $candidate])) {
      $candidate = sprintf('%s_%d', $base, $suffix);
      $suffix++;
      if ($suffix > 9999) {
        return '';
      }
    }

    return $candidate;
  }

  private function normalizeDelimiter(string $delimiter): string {
    $normalized = strtolower(trim($delimiter));
    if ($normalized === 'tab' || $normalized === '\\t') {
      return "\t";
    }
    if ($normalized === '') {
      return "\t";
    }
    return $delimiter;
  }

  /**
   * @param array<int, string|null> $row
   */
  private function isEmptyRow(array $row): bool {
    foreach ($row as $value) {
      if (trim((string) $value) !== '') {
        return false;
      }
    }
    return true;
  }

}

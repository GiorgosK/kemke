<?php

declare(strict_types=1);

use Drupal\Core\DrupalKernel;
use Drupal\field\Entity\FieldConfig;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;

$root = dirname(__DIR__);
$drupalRoot = $root . '/web';
$autoload = $drupalRoot . '/autoload.php';
if (!file_exists($autoload)) {
    throw new RuntimeException('Cannot locate web/autoload.php. Run this script from the project root.');
}

$cwd = getcwd();
chdir($drupalRoot);
$classLoader = require $autoload;

if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "MySQL PDO extension is not available in this PHP CLI. Run this script in the project runtime (for example `ddev exec php scripts/export_schema.php`) or enable pdo_mysql locally.\n");
    exit(1);
}

if (!defined('DRUPAL_DISABLED')) {
    define('DRUPAL_DISABLED', 0);
}
if (!defined('DRUPAL_OPTIONAL')) {
    define('DRUPAL_OPTIONAL', 1);
}
if (!defined('DRUPAL_REQUIRED')) {
    define('DRUPAL_REQUIRED', 2);
}

$kernel = DrupalKernel::createFromRequest(Request::createFromGlobals(), $classLoader, 'prod');
$kernel->boot();

$entityTypeManager = \Drupal::entityTypeManager();
$fieldManager = \Drupal::service('entity_field.manager');
$fieldTypeManager = \Drupal::service('plugin.manager.field.field_type');

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$outputDir = $root . '/docs/schema';
$outputFile = $outputDir . '/schema-overview.md';
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    throw new RuntimeException("Unable to create $outputDir");
}

$roleLabels = [
    'director_supervisor' => 'Προϊστάμενος Διεύθυνσης',
    'department_supervisor' => 'Προϊστάμενος Τμήματος',
    'secretariat' => 'Γραμματεία',
    'amke_user' => 'Χρήστης ΑΜΚΕ',
    'operator' => 'Χειριστής',
    'kemke_admin' => 'Διαχείριστής ΚΕΜΚΕ',
];

$roleOrder = array_keys($roleLabels);
$roleEntities = $entityTypeManager->getStorage('user_role')->loadMultiple(array_merge(['authenticated'], $roleOrder));

function friendlyTypeLabel(string $type, $fieldTypeManager): string {
    $definition = $fieldTypeManager->getDefinition($type, false);
    $label = $definition['label'] ?? $type;
    return (string) $label;
}

function formatCardinality(int $cardinality): string {
    return $cardinality === -1 ? 'unlimited' : (string) $cardinality;
}

function describeField(FieldConfig $field, $fieldTypeManager): string {
    $typeLabel = friendlyTypeLabel($field->getType(), $fieldTypeManager);
    $cardinality = formatCardinality($field->getFieldStorageDefinition()->getCardinality());
    $required = $field->isRequired() ? 'υποχρεωτικό' : 'προαιρετικό';
    $parts = [$typeLabel, $required, "πληθικότητα: $cardinality"];

    if ($field->getType() === 'entity_reference') {
        $settings = $field->getSettings();
        $targetType = $settings['target_type'] ?? '';
        $bundles = array_keys($settings['handler_settings']['target_bundles'] ?? []);
        $target = $targetType ?: 'entity';
        if ($bundles) {
            $target .= ' -> ' . implode(', ', $bundles);
        }
        $parts[] = "αναφορά σε $target";
    }

    if ($field->getType() === 'entity_reference_revisions') {
        $settings = $field->getSettings();
        $targetType = $settings['target_type'] ?? 'entity (revisions)';
        $bundles = array_keys($settings['handler_settings']['target_bundles'] ?? []);
        $target = $targetType;
        if ($bundles) {
            $target .= ' -> ' . implode(', ', $bundles);
        }
        $parts[] = "αναφορά σε $target";
    }

    return implode(' | ', $parts);
}

function collectFields(string $entityType, string $bundle, $fieldManager, $fieldTypeManager): array {
    $definitions = $fieldManager->getFieldDefinitions($entityType, $bundle);
    $fields = [];
    foreach ($definitions as $field) {
        if (!$field instanceof FieldConfig) {
            continue;
        }
        $fields[] = [
            'label' => $field->getLabel(),
            'name' => $field->getName(),
            'description' => trim((string) $field->getDescription()),
            'details' => describeField($field, $fieldTypeManager),
        ];
    }

    usort($fields, static fn(array $a, array $b): int => strcasecmp((string) $a['label'], (string) $b['label']));
    return $fields;
}

function renderMarkdownTable(array $headers, array $rows): array {
    $lines = [];
    $lines[] = '| ' . implode(' | ', $headers) . ' |';
    $lines[] = '| ' . implode(' | ', array_fill(0, count($headers), '---')) . ' |';
    foreach ($rows as $row) {
        $escaped = array_map(
            static fn(string $value): string => str_replace('|', '\\|', $value),
            $row
        );
        $lines[] = '| ' . implode(' | ', $escaped) . ' |';
    }
    return $lines;
}

function roleHasInheritedPermission(array $roleEntities, string $roleId, string $permission): bool {
    $candidates = ['authenticated'];
    if ($roleId !== 'authenticated') {
        $candidates[] = $roleId;
    }

    foreach ($candidates as $candidate) {
        $role = $roleEntities[$candidate] ?? null;
        if ($role instanceof RoleInterface && $role->hasPermission($permission)) {
            return true;
        }
    }

    return false;
}

function formatFieldPermissionAccess(array $roleEntities, string $roleId, string $fieldName): string {
    $viewAny = roleHasInheritedPermission($roleEntities, $roleId, 'view ' . $fieldName);
    $viewOwn = roleHasInheritedPermission($roleEntities, $roleId, 'view own ' . $fieldName);
    $editAny = roleHasInheritedPermission($roleEntities, $roleId, 'edit ' . $fieldName);
    $editOwn = roleHasInheritedPermission($roleEntities, $roleId, 'edit own ' . $fieldName);

    $parts = [];
    if ($viewAny) {
        $parts[] = 'Π';
    } elseif ($viewOwn) {
        $parts[] = 'Π ιδ.';
    }

    if ($editAny) {
        $parts[] = 'Ε';
    } elseif ($editOwn) {
        $parts[] = 'Ε ιδ.';
    }

    return $parts !== [] ? implode('/', $parts) : '-';
}

function buildBundleMatrixRows(array $fields, array $roleLabels, array $defaultAccess, array $notesByField = []): array {
    $rows = [];
    foreach ($fields as $field) {
        $row = [
            sprintf('%s (`%s`)', $field['label'], $field['name']),
        ];
        foreach (array_keys($roleLabels) as $roleId) {
            $row[] = $defaultAccess[$roleId] ?? '-';
        }
        $row[] = $notesByField[$field['name']] ?? '-';
        $rows[] = $row;
    }
    return $rows;
}

$incomingFieldNotes = [
    'field_documents' => 'Conditional UI access. Για ΚΕΜΚΕ ρόλους εμφανίζεται κυρίως στη δημιουργία ή μέχρι να υπάρχει primary document. Για Χρήστη ΑΜΚΕ εμφανίζεται στη δημιουργία και στο state `temp` όσο δεν υπάρχει primary document.',
    'field_protocol_number_sender' => 'Conditional UI access. Κρύβεται όταν υπάρχει ήδη primary document ή όταν το state δεν επιτρέπει αλλαγή.',
    'field_related_incoming' => 'Conditional UI access. Για ΚΕΜΚΕ ρόλους εμφανίζεται όταν υπάρχει primary document. Για Χρήστη ΑΜΚΕ χρησιμοποιείται κυρίως στο `temp` όταν υπάρχει primary document.',
    'field_subject' => 'Programmatic locking από `field_non_empty_lock`. Για Προϊστάμενος Διεύθυνσης και Προϊστάμενος Τμήματος κλειδώνει μετά την πρώτη τιμή. Για Χειριστής κλειδώνει σε edit. Για Χρήστη ΑΜΚΕ γίνεται read-only εκτός `temp`.',
    'field_interim_deadline' => 'Programmatic locking για Χρήστη ΑΜΚΕ σε edit από `field_non_empty_lock`.',
    'field_supervisor' => 'Programmatic locking για Προϊστάμενος Τμήματος και Χειριστής μετά την αρχική συμπλήρωση.',
    'field_basic_operator' => 'Programmatic locking για Χειριστής μετά την αρχική συμπλήρωση.',
    'field_operators' => 'Programmatic locking για Χειριστής μετά την αρχική συμπλήρωση.',
    'field_legal_entity' => 'Για Χρήστη ΑΜΚΕ δεν αποτελεί βασικό editable field της φόρμας. Συμπληρώνεται αυτόματα από το profile μέσω `amke_tweaks` και χρησιμοποιείται επιπλέον από `amke_access_incoming_by_ref` για view/update filtering.',
    'field_remarks' => 'Για Χρήστη ΑΜΚΕ κρύβεται όταν το incoming βρίσκεται σε `temp`.',
    'field_notes' => 'Σχόλιο από ΣΗΔΕ. Εμφανίζεται κυρίως μετά από ολοκλήρωση διασύνδεσης ή polling.',
    'field_plan_signed' => 'Κρύβεται όταν είναι κενό και πριν την παραγωγή signed copy.',
    'field_plan_dt_api_response' => 'Τεχνικό/diagnostic log πεδίο για διασύνδεση Docutracks. Κρύβεται όταν είναι κενό.',
    'field_ref_id' => 'Κρύβεται όταν είναι κενό σε edit και δεν εμφανίζεται ως editable field σε όλα τα στάδια.',
];

$contactDefaultAccess = [
    'director_supervisor' => 'Π/Ε',
    'department_supervisor' => 'Π/Ε',
    'secretariat' => 'Π/Ε',
    'amke_user' => 'Π',
    'operator' => 'Π/Ε',
    'kemke_admin' => 'Π/Ε',
];

$incomingDefaultAccess = [
    'director_supervisor' => 'Π/Ε*',
    'department_supervisor' => 'Π/Ε*',
    'secretariat' => 'Π/Ε*',
    'amke_user' => 'Π/Ε ιδ.*',
    'operator' => 'Π/Ε*',
    'kemke_admin' => 'Π/Ε*',
];

$repositoryDefaultAccess = [
    'director_supervisor' => 'Π/Ε',
    'department_supervisor' => 'Π/Ε',
    'secretariat' => 'Π/Ε',
    'amke_user' => 'Π',
    'operator' => 'Π/Ε',
    'kemke_admin' => 'Π/Ε',
];

$lines = [];
$lines[] = '# Επισκόπηση σχήματος βάσης';
$lines[] = '';
$lines[] = '- Generated: ' . $now->format('Y-m-d H:i:s \U\T\C');
$lines[] = '- Scope: custom bundles και custom fields';
$lines[] = '- Focus: content types, taxonomies, user entity, field-level access';
$lines[] = '';
$lines[] = '> Σημείωση ορολογίας: Η ονομασία `incoming` (content type) αρχικά σήμαινε "εισερχόμενα έγγραφα". Στην πορεία της ανάπτυξης της πλατφόρμας χρησιμοποιήθηκε πρακτικά με την ευρύτερη έννοια "έγγραφο/έγγραφα". Επομένως, όπου εμφανίζεται ο όρος `incoming` ως αναφορά ή σε όνομα custom module, στο παρόν κείμενο διαβάζεται ως "έγγραφο/έγγραφα".';
$lines[] = '';
$lines[] = '## Περιεχόμενο';
$lines[] = '';
$lines[] = '1. Σχήμα περιεχομένου';
$lines[] = '2. Λεξιλόγια taxonomy';
$lines[] = '3. Οντότητα χρήστη';
$lines[] = '4. Πίνακες πρόσβασης ανά field και ρόλο';
$lines[] = '';
$lines[] = '---';
$lines[] = '';

$nodePurposeOverrides = [
    'contact' => 'Επαφές που συγχρονίζονται από payload αποστολέα Docutracks.',
    'incoming' => 'Κεντρική οντότητα εισερχομένων εγγράφων και workflow επεξεργασίας.',
    'repository_item' => 'Στοιχεία αποθετηρίου με αρχεία και κατηγοριοποίηση.',
];

$nodeTypes = $entityTypeManager->getStorage('node_type')->loadMultiple();
if ($nodeTypes) {
    $lines[] = '## 1. Σχήμα περιεχομένου';
    foreach ($nodeTypes as $type) {
        if (in_array($type->id(), ['article', 'page'], true)) {
            continue;
        }

        $lines[] = '';
        $lines[] = sprintf('### %s (`%s`)', $type->label(), $type->id());
        $description = $nodePurposeOverrides[$type->id()] ?? trim((string) $type->getDescription());
        $lines[] = '- Σκοπός: ' . ($description !== '' ? $description : 'n/a');
        $fields = collectFields('node', $type->id(), $fieldManager, $fieldTypeManager);
        if ($fields === []) {
            $lines[] = '- Πεδία: δεν υπάρχουν custom fields.';
            continue;
        }

        $lines[] = '- Πεδία:';
        foreach ($fields as $field) {
            $suffix = $field['description'] !== '' ? ' — ' . $field['description'] : '';
            $lines[] = sprintf('  - %s (`%s`): %s%s', $field['label'], $field['name'], $field['details'], $suffix);
        }
    }
    $lines[] = '';
    $lines[] = '---';
    $lines[] = '';
}

$taxonomyPurposeOverrides = [
    'case' => 'Λεξιλόγιο υποθέσεων.',
    'incoming_subtype' => 'Λεξιλόγιο υποκατηγοριών εγγράφου.',
    'incoming_type' => 'Λεξιλόγιο κατηγοριών εγγράφου.',
    'legal_entity' => 'Λεξιλόγιο φορέων.',
    'priority' => 'Λεξιλόγιο προτεραιοτήτων.',
    'repository_type' => 'Κατηγορίες για τα στοιχεία του αποθετηρίου.',
    'tags' => 'Λεξιλόγιο λέξεων-κλειδιών.',
];

$vocabularies = $entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple();
if ($vocabularies) {
    $lines[] = '## 2. Λεξιλόγια taxonomy';
    foreach ($vocabularies as $vocabulary) {
        $lines[] = '';
        $lines[] = sprintf('### %s (`%s`)', $vocabulary->label(), $vocabulary->id());
        $description = $taxonomyPurposeOverrides[$vocabulary->id()] ?? trim((string) $vocabulary->get('description'));
        $lines[] = '- Σκοπός: ' . ($description !== '' ? $description : 'n/a');
        $fields = collectFields('taxonomy_term', $vocabulary->id(), $fieldManager, $fieldTypeManager);
        if ($fields === []) {
            $lines[] = '- Πεδία: δεν υπάρχουν custom fields.';
            continue;
        }

        $lines[] = '- Πεδία:';
        foreach ($fields as $field) {
            $suffix = $field['description'] !== '' ? ' — ' . $field['description'] : '';
            $lines[] = sprintf('  - %s (`%s`): %s%s', $field['label'], $field['name'], $field['details'], $suffix);
        }
    }
    $lines[] = '';
    $lines[] = '---';
    $lines[] = '';
}

$lines[] = '## 3. Οντότητα χρήστη';
$lines[] = '';
$userFields = collectFields('user', 'user', $fieldManager, $fieldTypeManager);
if ($userFields === []) {
    $lines[] = '- Πεδία: δεν υπάρχουν custom fields.';
} else {
    $lines[] = '- Πεδία:';
    foreach ($userFields as $field) {
        $suffix = $field['description'] !== '' ? ' — ' . $field['description'] : '';
        $lines[] = sprintf('  - %s (`%s`): %s%s', $field['label'], $field['name'], $field['details'], $suffix);
    }
}
$lines[] = '';
$lines[] = '---';
$lines[] = '';

$lines[] = '## 4. Πίνακες πρόσβασης ανά field και ρόλο';
$lines[] = '';
$lines[] = '- Υπόμνημα: `Π` = προβολή, `Ε` = επεξεργασία, `ιδ.` = μόνο own content/profile, `*` = ισχύουν πρόσθετοι programmatic περιορισμοί.';
$lines[] = '- Οι πίνακες συνδυάζουν γενικά permissions, `field_permissions` όπου υπάρχει, και programmatic restrictions που εντοπίστηκαν στα custom modules.';
$lines[] = '';

$bundleFieldSets = [
    'contact' => collectFields('node', 'contact', $fieldManager, $fieldTypeManager),
    'incoming' => collectFields('node', 'incoming', $fieldManager, $fieldTypeManager),
    'repository_item' => collectFields('node', 'repository_item', $fieldManager, $fieldTypeManager),
];

$lines[] = '### Contact (`contact`)';
$lines[] = '';
$lines = array_merge(
    $lines,
    renderMarkdownTable(
        array_merge(['Field'], array_values($roleLabels), ['Παρατηρήσεις']),
        buildBundleMatrixRows($bundleFieldSets['contact'], $roleLabels, $contactDefaultAccess)
    )
);
$lines[] = '';
$lines[] = '### Έγγραφο (`incoming`)';
$lines[] = '';
$lines = array_merge(
    $lines,
    renderMarkdownTable(
        array_merge(['Field'], array_values($roleLabels), ['Παρατηρήσεις']),
        buildBundleMatrixRows($bundleFieldSets['incoming'], $roleLabels, $incomingDefaultAccess, $incomingFieldNotes)
    )
);
$lines[] = '';
$lines[] = 'Πρόσθετες παρατηρήσεις για `incoming`:';
$lines[] = '- Το `amke_access_incoming_by_ref` περιορίζει `view` και `update` για `Χρήστης ΑΜΚΕ` όταν το `field_legal_entity` του εισερχομένου δεν ταιριάζει με το profile του χρήστη.';
$lines[] = '- Το `incoming_views_tweaks` περιορίζει τις λίστες: ο `Χειριστής` βλέπει τα εισερχόμενα όπου είναι `field_operators` ή `field_basic_operator`, ενώ ο `Προϊστάμενος Τμήματος` όσα τον έχουν στο `field_supervisor`.';
$lines[] = '- Το `field_non_empty_lock` κλειδώνει συγκεκριμένα fields όταν αποκτήσουν τιμή, ώστε να αποφεύγονται μεταγενέστερες αλλοιώσεις workflow δεδομένων.';
$lines[] = '- Το `amke_tweaks` αλλάζει programmatically τη φόρμα του `Χρήστης ΑΜΚΕ` ανάλογα με το state (`temp`, `draft`, `pending_issues`) και το αν υπάρχει primary document.';
$lines[] = '';
$lines[] = '### Αποθετήριο (`repository_item`)';
$lines[] = '';
$lines = array_merge(
    $lines,
    renderMarkdownTable(
        array_merge(['Field'], array_values($roleLabels), ['Παρατηρήσεις']),
        buildBundleMatrixRows($bundleFieldSets['repository_item'], $roleLabels, $repositoryDefaultAccess)
    )
);
$lines[] = '';
$lines[] = '### Custom user fields με `field_permissions`';
$lines[] = '';

$userFieldNotes = [
    'field_notifications' => 'Explicit `field_permissions`. Όλοι οι operational roles έχουν `edit own field_notifications`. Το `kemke_admin` έχει και global `edit field_notifications` και `create field_notifications`.',
    'field_temporary_disabled' => 'Explicit `field_permissions`. Προβολή από όλους τους authenticated users. Επεξεργασία μόνο από `Προϊστάμενος Διεύθυνσης`, `Γραμματεία`, `Διαχείριστής ΚΕΜΚΕ`.',
    'field_serial_number' => 'Explicit `field_permissions`. Προβολή από όλους τους authenticated users. Επεξεργασία από `Προϊστάμενος Διεύθυνσης`, `Προϊστάμενος Τμήματος`, `Γραμματεία`, `Διαχείριστής ΚΕΜΚΕ`.',
    'field_legal_entity' => 'Το storage είναι δηλωμένο με `field_permissions`, αλλά στο exported role config δεν υπάρχει explicit grant για τους operational roles. Επιπλέον το `users_tweaks` το εμφανίζει στο user form μόνο όταν ο target χρήστης έχει role `Χρήστης ΑΜΚΕ`.',
    'field_dt_config' => 'Τεχνικό πεδίο. Δεν βρέθηκε explicit operational permission στο exported role config.',
];

$userFieldsForPermissions = [
    'field_notifications' => 'Επιλογή Ειδοποιήσεων',
    'field_temporary_disabled' => 'Προσωρινά ανενεργός χρήστης',
    'field_serial_number' => 'Αύξων αριθμός',
    'field_legal_entity' => 'Φορέας',
    'field_dt_config' => 'Docutracks config',
];

$userPermissionRows = [];
foreach ($userFieldsForPermissions as $fieldName => $label) {
    $row = [sprintf('%s (`%s`)', $label, $fieldName)];
    foreach ($roleOrder as $roleId) {
        $row[] = formatFieldPermissionAccess($roleEntities, $roleId, $fieldName);
    }
    $row[] = $userFieldNotes[$fieldName] ?? '-';
    $userPermissionRows[] = $row;
}

$lines = array_merge(
    $lines,
    renderMarkdownTable(
        array_merge(['Field'], array_values($roleLabels), ['Παρατηρήσεις']),
        $userPermissionRows
    )
);
$lines[] = '';
$lines[] = 'Συμπέρασμα για user profile access:';
$lines[] = '- Το `field_permissions` χρησιμοποιείται κυρίως για τα πεδία `field_notifications`, `field_temporary_disabled`, `field_serial_number`, `field_legal_entity`, `field_dt_config`.';
$lines[] = '- Σε επίπεδο λειτουργικής χρήσης, κρίσιμα operational πεδία είναι κυρίως τα `field_notifications`, `field_temporary_disabled`, `field_serial_number` και το `field_legal_entity` για τους χρήστες `Χρήστης ΑΜΚΕ`.';
$lines[] = '- Το `incoming_notifications` διαβάζει το `field_notifications` για να αποφασίσει αν κάθε χρήστης λαμβάνει platform notifications, email notifications ή κανένα από τα δύο.';

$markdown = implode(PHP_EOL, $lines) . PHP_EOL;
if (file_put_contents($outputFile, $markdown) === false) {
    throw new RuntimeException("Unable to write $outputFile");
}

chdir($cwd);
echo "Schema handover document written to $outputFile\n";

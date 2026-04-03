<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__);
$modulesDir = $root . '/web/modules/custom';
$outputDir = $root . '/docs/architecture';
$outputFile = $outputDir . '/custom-modules-overview.md';

if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    throw new RuntimeException("Unable to create $outputDir");
}

$coreExtension = Yaml::parseFile($root . '/config/core.extension.yml');
$enabledModules = $coreExtension['module'] ?? [];
$enabledLookup = [];
foreach ($enabledModules as $moduleName => $weight) {
    if (str_starts_with((string) $moduleName, 'core')) {
        continue;
    }
    $enabledLookup[(string) $moduleName] = true;
}

$descriptions = [
    'activities_mods' => 'Επεκτείνει το activity log για τα `incoming` με ανίχνευση αλλαγών field-by-field και moderation transitions.',
    'admin_theme_per_role' => 'Εφαρμόζει admin theme σε επιλεγμένο role μόνο για admin-like πρόσβαση.',
    'ajax_form_test' => 'Δοκιμαστικό module για Ajax form και external POST calls.',
    'amke_access_incoming_by_ref' => 'Επιβάλλει access filtering για `Χρήστης ΑΜΚΕ` με βάση το `field_legal_entity` στα `incoming`.',
    'amke_tweaks' => 'Τροποποιεί τη φόρμα και τη ροή εργασίας του `Χρήστης ΑΜΚΕ` στα `incoming`.',
    'case_path_titles' => 'Εμπλουτίζει τους τίτλους σελίδων για υποθέσεις/εισερχόμενα.',
    'case_tweaks' => 'Παράγει και συντηρεί το `field_ref_id` για taxonomy terms `case`.',
    'case_xls_export' => 'Βοηθητικά για εξαγωγές Views XLS με ιεραρχία υποθέσεων.',
    'checkbox_radios' => 'Μετατρέπει επιλεγμένα checkbox widgets σε single-select συμπεριφορά.',
    'custom_permissions_access' => 'Δίνει ελεγχόμενη πρόσβαση στη σελίδα permissions για operational admin role.',
    'datetime_optional_time' => 'Formatter/UX βελτίωση για datetime fields με προαιρετικό time μέρος.',
    'edit_settings' => 'Εσωτερικές φόρμες επεξεργασίας ρυθμίσεων και manuals.',
    'external_entities_extra' => 'Βοηθητικά για custom integrations με external entities.',
    'external_form_test' => 'Δοκιμαστικό external POST form.',
    'field_non_empty_lock' => 'Κλειδώνει ή κρύβει πεδία όταν συμπληρωθούν, ώστε να προστατεύεται το workflow data model.',
    'greek_holidays' => 'Entity και utilities για ελληνικές αργίες, χρήσιμα σε deadline logic και reports.',
    'incoming_api' => 'JSON API για δημιουργία `incoming` και μεταφόρτωση αρχείων από εξωτερικά συστήματα.',
    'incoming_change_state' => 'Τοπική φόρμα αλλαγής moderation state για `incoming`.',
    'incoming_create_select_case' => 'Γρήγορη δημιουργία/επιλογή υπόθεσης μέσα από τη φόρμα incoming.',
    'incoming_edit_tweaks' => 'Redirect και usability tweaks μετά από save/edit σε incoming.',
    'incoming_form_validations' => 'Client-side και form-level validations για incoming workflow.',
    'incoming_notifications' => 'Στέλνει email και platform notifications με βάση state changes και remarks.',
    'incoming_plan_correction' => 'Ροή αποστολής διορθωμένου σχεδίου προς ΣΗΔΕ/Docutracks για ολοκληρωμένα incoming.',
    'incoming_related_field' => 'Διαχειρίζεται τις σχέσεις με άλλα incoming και κανόνες σχετικών εγγράφων.',
    'incoming_remarks' => 'Διαχειρίζεται metadata, ορατότητα και συμπεριφορά remark paragraphs.',
    'incoming_select2_filters' => 'Ενεργοποιεί Select2 σε επιλεγμένα exposed filters των Views.',
    'incoming_state_validations' => 'Ελέγχει business rules πριν από transitions του workflow incoming.',
    'incoming_tweaks' => 'Μικρές λειτουργικές προσαρμογές στη φόρμα incoming και στη διασύνδεση Docutracks.',
    'incoming_views_pdf_tweaks' => 'Προσαρμογές στις PDF εξαγωγές των incoming Views.',
    'incoming_views_tweaks' => 'Περιορίζει τα αποτελέσματα λιστών incoming ανά ρόλο και assignment.',
    'kemke_breadcrumbs' => 'Κεντρική παραγωγή breadcrumb για υποθέσεις και incoming.',
    'kemke_gsis_pa_oauth2_client' => 'Custom `oauth2_client` plugin για oAuth2.0.PA και logging κλήσεων GSIS PA.',
    'kemke_manuals' => 'Role-based σελίδα manuals/help υλικού.',
    'kemke_reports' => 'Reports και υπολογισμοί KPI πάνω στα `incoming`.',
    'kemke_users_gsis_pa_auth2' => 'Glue logic για σύνδεση μέσω GSIS PA, αντιστοίχιση σε local user και sync επιλεγμένων profile fields.',
    'mock_api' => 'Mock JSON API για δοκιμές.',
    'node_edit_concurrency_warning' => 'Προειδοποίηση όταν φόρμα edit έχει γίνει stale.',
    'node_save_concurrency' => 'Αποτρέπει stale saves σε concurrent επεξεργασία.',
    'operators_reference_view' => 'View query alterations για στοιχεία απουσιών χειριστών.',
    'opinion_ref_id_tweaks' => 'Παραγωγή και ρυθμίσεις για opinion reference ids.',
    'permissions_filtering' => 'Κρύβει μη επιχειρησιακά permission sets από το UI διαχείρισης.',
    'readonly_admin_simulator' => 'Read-only προσομοίωση admin-like role για παρατηρητή.',
    'save_draft' => 'Client-side αποθήκευση/φόρτωση draft για incoming φόρμα.',
    'select2_level_class' => 'CSS classes βάθους σε Select2 options για nested taxonomies.',
    'side_api' => 'Client integration προς ΣΗΔΕ/Docutracks για login, register, fetch και download αρχείων.',
    'side_polling' => 'Cron/polling manager που παρακολουθεί την εξέλιξη σχεδίων και ενημερώνει το Drupal.',
    'taxonomy_tweaks' => 'Χρήσιμες προσαρμογές σε taxonomy forms και behaviors.',
    'user_import' => 'Drush import χρηστών από CSV/TSV.',
    'user_pending_role_notice' => 'Notice/redirect για users που δεν έχουν ακόμη operational role.',
    'users_tweaks' => 'Προσαρμογές στο user profile, redirects, user form και sync με Docutracks στοιχεία.',
    'views_entity_reference_select2' => 'Select2 entity-reference φίλτρα πάνω σε Views displays.',
    'views_year_filters' => 'Επαναχρησιμοποιήσιμα Views filters για date-by-year αναζήτηση.',
];

$importantModules = [
    'kemke_users_gsis_pa_auth2' => [
        'Σημείο εισόδου για το business login flow μέσω GSIS Public Administration.',
        'Η αντιστοίχιση δεν γίνεται με dynamic πίνακα `ΑΦΜ -> role`. Το `ΑΦΜ` και εναλλακτικά το `userid` χρησιμοποιούνται ως κλειδιά ταυτοποίησης σε υπάρχον local user (`field_gsis_afm`, `field_gsis_userid`, fallback `field_gsis_info`).',
        'Χρήστης μπορεί να κάνει authenticate επιτυχώς μέσω ΚΔΔ, αλλά αυτό δεν αρκεί για επιχειρησιακή χρήση της πλατφόρμας. Ο κατάλληλος ρόλος πρέπει να έχει ήδη αποδοθεί ή να αποδοθεί από `kemke_admin` στον local λογαριασμό.',
        'Ο ρόλος προκύπτει από τον ήδη υπάρχοντα local λογαριασμό. Αν επιτραπεί δημιουργία νέου χρήστη, μπορούν να προστεθούν `default_roles` από settings, όμως στο τρέχον `settings.local.php` η τιμή είναι κενή.',
    ],
    'kemke_gsis_pa_oauth2_client' => [
        'Ορίζει custom `oauth2_client` plugin και συγκεντρώνει logging για authorization, token exchange και `userinfo` calls.',
        'Υποστηρίζει περιβάλλοντα `mock`, `test`, `live` με settings overrides.',
    ],
    'incoming_api' => [
        'Παρέχει JSON endpoint για δημιουργία `incoming` από εξωτερικό producer με permission `create incoming via api`.',
        'Κάνει validation του payload, δημιουργεί node/paragraph/file entities και συνδέει νέο εισερχόμενο με υπάρχον μέσω `field_ref_id` όπου απαιτείται.',
    ],
    'side_api' => [
        'Αποτελεί τον client προς ΣΗΔΕ/Docutracks για login, αποστολή σχεδίου, ανάκτηση metadata και download παραγόμενων αρχείων.',
        'Το αποτέλεσμα των κλήσεων αποτυπώνεται και στο `field_plan_dt_api_response` ώστε να υπάρχει operational trace μέσα στο incoming.',
    ],
    'side_polling' => [
        'Τρέχει μέσω `cron` και εκτελεί due jobs για plan initial / correction handlers.',
        'Παρακολουθεί την κατάσταση σχεδίων που έχουν σταλεί στη ΣΗΔΕ, ενημερώνει το incoming και τροφοδοτεί downstream notifications/activity.',
    ],
    'activities_mods' => [
        'Αντικαθιστά/επεκτείνει το activity logging μόνο όπου χρειάζεται για `incoming`.',
        'Καταγράφει αλλαγές πεδίων και moderation transitions ώστε το audit trail να είναι πιο χρήσιμο επιχειρησιακά από ένα generic entity log.',
    ],
    'incoming_notifications' => [
        'Μετατρέπει τα moderation state changes και τα remarks σε email/platform notifications.',
        'Συνδυάζεται με `field_notifications` για να εφαρμόζει προτίμηση delivery ανά χρήστη.',
    ],
    'incoming_views_tweaks' => [
        'Εφαρμόζει role-based scoping στις λίστες incoming.',
        'Ο `Χειριστής` βλέπει κυρίως τα δικά του assignments, ο `Προϊστάμενος Τμήματος` τα δικά του supervisor assignments, ενώ `Γραμματεία`, `Προϊστάμενος Διεύθυνσης` και `Διαχείριστής ΚΕΜΚΕ` έχουν ευρύτερη ορατότητα.',
    ],
    'amke_access_incoming_by_ref' => [
        'Επιβάλλει access filtering για `Χρήστης ΑΜΚΕ` με βάση το `field_legal_entity` του incoming και του profile.',
        'Επηρεάζει τόσο canonical access όσο και Views queries, ώστε το access model να είναι συνεπές σε σελίδες και λίστες.',
    ],
];

$infoFiles = glob($modulesDir . '/*/*.info.yml') ?: [];
sort($infoFiles);

$moduleRows = [];
foreach ($infoFiles as $infoFile) {
    $info = Yaml::parseFile($infoFile);
    $machineName = basename(dirname($infoFile));
    if (!isset($enabledLookup[$machineName])) {
        continue;
    }
    $moduleRows[] = [
        'machine_name' => $machineName,
        'name' => (string) ($info['name'] ?? $machineName),
        'description' => $descriptions[$machineName] ?? (string) ($info['description'] ?? 'n/a'),
    ];
}

function renderTable(array $headers, array $rows): array {
    $lines = [];
    $lines[] = '| ' . implode(' | ', $headers) . ' |';
    $lines[] = '| ' . implode(' | ', array_fill(0, count($headers), '---')) . ' |';
    foreach ($rows as $row) {
        $row = array_map(
            static fn(string $value): string => str_replace('|', '\\|', $value),
            $row
        );
        $lines[] = '| ' . implode(' | ', $row) . ' |';
    }
    return $lines;
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$lines = [];
$lines[] = '# Custom modules και αρχιτεκτονική';
$lines[] = '';
$lines[] = '- Generated: ' . $now->format('Y-m-d H:i:s \U\T\C');
$lines[] = '- Scope: Ενεργά custom modules που υπάρχουν στο παρόν codebase snapshot';
$lines[] = '';
$lines[] = '> Σημείωση ορολογίας: Η ονομασία `incoming` (content type) αρχικά σήμαινε "εισερχόμενα έγγραφα". Στην πορεία της ανάπτυξης της πλατφόρμας χρησιμοποιήθηκε πρακτικά με την ευρύτερη έννοια "έγγραφο/έγγραφα". Επομένως, όπου εμφανίζεται ο όρος `incoming` ως αναφορά ή σε όνομα custom module, στο παρόν κείμενο διαβάζεται ως "έγγραφο/έγγραφα".';
$lines[] = '';
$lines[] = '## 1. Συνοπτική αρχιτεκτονική';
$lines[] = '';
$lines[] = '- Το RBAC βασίζεται κυρίως σε exported `user.role.*` config, σε `field_permissions` για επιλεγμένα user fields και σε πρόσθετους programmatic περιορισμούς μέσα στα custom modules.';
$lines[] = '- Για τα `incoming`, τα γενικά permissions συμπληρώνονται από access/query filters στα `amke_access_incoming_by_ref`, `incoming_views_tweaks`, `amke_tweaks` και `field_non_empty_lock`.';
$lines[] = '- Για authentication μέσω oAuth2.0.PA, το GSIS payload δεν χαρτογραφείται απευθείας σε role. Το `ΑΦΜ` και το `userid` χρησιμοποιούνται για ταυτοποίηση existing local user, από τον οποίο κληρονομείται ο επιχειρησιακός ρόλος. Χρήστης μπορεί να κάνει authenticate επιτυχώς μέσω ΚΔΔ, αλλά για να αποκτήσει επιχειρησιακή πρόσβαση στην πλατφόρμα απαιτείται ο `kemke_admin` να του αποδώσει τον κατάλληλο ρόλο.';
$lines[] = '- Το activity log βασίζεται στο contrib `activities`, αλλά το `activities_mods` προσθέτει business-aware logging για field changes και workflow transitions.';
$lines[] = '';
$lines[] = '## 2. Κρίσιμα modules';
$lines[] = '';
foreach ($importantModules as $machineName => $moduleLines) {
    $title = $machineName;
    foreach ($moduleRows as $row) {
        if ($row['machine_name'] === $machineName) {
            $title = $row['name'] . ' (`' . $machineName . '`)';
            break;
        }
    }
    $lines[] = '### ' . $title;
    foreach ($moduleLines as $moduleLine) {
        $lines[] = '- ' . $moduleLine;
    }
    $lines[] = '';
}

$lines[] = '## 3. Ροή δεδομένων Drupal -> ΣΗΔΕ';
$lines[] = '';
$lines[] = '```text';
$lines[] = 'Χρήστης / API client';
$lines[] = '        |';
$lines[] = '        v';
$lines[] = 'Drupal incoming form / incoming_api';
$lines[] = '        |';
$lines[] = '        v';
$lines[] = 'Node incoming + files + paragraphs + workflow state';
$lines[] = '        |';
$lines[] = '        +--> activities_mods -> activity log';
$lines[] = '        +--> incoming_notifications -> email / platform notifications';
$lines[] = '        |';
$lines[] = '        v';
$lines[] = 'side_api (Docutracks client)';
$lines[] = '        |';
$lines[] = '        v';
$lines[] = 'ΣΗΔΕ / Docutracks';
$lines[] = '        |';
$lines[] = '        v';
$lines[] = 'side_polling (cron) / incoming_plan_correction';
$lines[] = '        |';
$lines[] = '        v';
$lines[] = 'Ενημέρωση Drupal incoming';
$lines[] = '        |';
$lines[] = '        +--> field_plan_dt_api_response';
$lines[] = '        +--> field_notes / field_plan_signed / state changes';
$lines[] = '        +--> activities_mods / incoming_notifications';
$lines[] = '```';
$lines[] = '';
$lines[] = '## 4. Λίστα ενεργών custom modules';
$lines[] = '';

$tableRows = [];
foreach ($moduleRows as $row) {
    $tableRows[] = [
        $row['name'] . ' (`' . $row['machine_name'] . '`)',
        $row['description'],
    ];
}

$lines = array_merge($lines, renderTable(['Module', 'Σύντομη περιγραφή'], $tableRows));
$lines[] = '';
$lines[] = '## 5. RBAC, OAuth2 και activity log';
$lines[] = '';
$lines[] = '- RBAC: ο βασικός κορμός είναι το exported Drupal role configuration. Για συγκεκριμένα user fields χρησιμοποιείται το `field_permissions`, ενώ για τα `incoming` εφαρμόζονται και dynamic restrictions ανά ρόλο/state/assignment.';
$lines[] = '- OAuth2.0.PA: η ροή περνά από `kemke_gsis_pa_oauth2_client` και `kemke_users_gsis_pa_auth2`. Το `ΑΦΜ` και το `userid` λειτουργούν ως lookup keys για local account matching. Δεν υπάρχει στο codebase ξεχωριστός πίνακας κανόνων `ΑΦΜ -> role`. Η επιτυχής σύνδεση μέσω ΚΔΔ δεν αρκεί από μόνη της για επιχειρησιακή πρόσβαση: ο ρόλος του χρήστη στην πλατφόρμα πρέπει να έχει αποδοθεί από `kemke_admin`.';
$lines[] = '- Activity log: το `activities_mods` συνθέτει human-readable change sets για τα `incoming` και κρατά χρήσιμο ιστορικό moderation και field μεταβολών.';

$markdown = implode(PHP_EOL, $lines) . PHP_EOL;
if (file_put_contents($outputFile, $markdown) === false) {
    throw new RuntimeException("Unable to write $outputFile");
}

echo "Custom modules overview written to $outputFile\n";

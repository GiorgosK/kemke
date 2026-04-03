# Custom modules και αρχιτεκτονική

- Generated: 2026-04-03 06:38:29 UTC
- Scope: Ενεργά custom modules που υπάρχουν στο παρόν codebase snapshot

> Σημείωση ορολογίας: Η ονομασία `incoming` (content type) αρχικά σήμαινε "εισερχόμενα έγγραφα". Στην πορεία της ανάπτυξης της πλατφόρμας χρησιμοποιήθηκε πρακτικά με την ευρύτερη έννοια "έγγραφο/έγγραφα". Επομένως, όπου εμφανίζεται ο όρος `incoming` ως αναφορά ή σε όνομα custom module, στο παρόν κείμενο διαβάζεται ως "έγγραφο/έγγραφα".

## 1. Συνοπτική αρχιτεκτονική

- Το RBAC βασίζεται κυρίως σε exported `user.role.*` config, σε `field_permissions` για επιλεγμένα user fields και σε πρόσθετους programmatic περιορισμούς μέσα στα custom modules.
- Για τα `incoming`, τα γενικά permissions συμπληρώνονται από access/query filters στα `amke_access_incoming_by_ref`, `incoming_views_tweaks`, `amke_tweaks` και `field_non_empty_lock`.
- Για authentication μέσω oAuth2.0.PA, το GSIS payload δεν χαρτογραφείται απευθείας σε role. Το `ΑΦΜ` και το `userid` χρησιμοποιούνται για ταυτοποίηση existing local user, από τον οποίο κληρονομείται ο επιχειρησιακός ρόλος. Χρήστης μπορεί να κάνει authenticate επιτυχώς μέσω ΚΔΔ, αλλά για να αποκτήσει επιχειρησιακή πρόσβαση στην πλατφόρμα απαιτείται ο `kemke_admin` να του αποδώσει τον κατάλληλο ρόλο.
- Το activity log βασίζεται στο contrib `activities`, αλλά το `activities_mods` προσθέτει business-aware logging για field changes και workflow transitions.

## 2. Κρίσιμα modules

### KEMKE Users GSIS PA Auth2 (`kemke_users_gsis_pa_auth2`)
- Σημείο εισόδου για το business login flow μέσω GSIS Public Administration.
- Η αντιστοίχιση δεν γίνεται με dynamic πίνακα `ΑΦΜ -> role`. Το `ΑΦΜ` και εναλλακτικά το `userid` χρησιμοποιούνται ως κλειδιά ταυτοποίησης σε υπάρχον local user (`field_gsis_afm`, `field_gsis_userid`, fallback `field_gsis_info`).
- Χρήστης μπορεί να κάνει authenticate επιτυχώς μέσω ΚΔΔ, αλλά αυτό δεν αρκεί για επιχειρησιακή χρήση της πλατφόρμας. Ο κατάλληλος ρόλος πρέπει να έχει ήδη αποδοθεί ή να αποδοθεί από `kemke_admin` στον local λογαριασμό.
- Ο ρόλος προκύπτει από τον ήδη υπάρχοντα local λογαριασμό. Αν επιτραπεί δημιουργία νέου χρήστη, μπορούν να προστεθούν `default_roles` από settings, όμως στο τρέχον `settings.local.php` η τιμή είναι κενή.

### KEMKE GSIS PA OAuth2 Client (`kemke_gsis_pa_oauth2_client`)
- Ορίζει custom `oauth2_client` plugin και συγκεντρώνει logging για authorization, token exchange και `userinfo` calls.
- Υποστηρίζει περιβάλλοντα `mock`, `test`, `live` με settings overrides.

### Incoming API (`incoming_api`)
- Παρέχει JSON endpoint για δημιουργία `incoming` από εξωτερικό producer με permission `create incoming via api`.
- Κάνει validation του payload, δημιουργεί node/paragraph/file entities και συνδέει νέο εισερχόμενο με υπάρχον μέσω `field_ref_id` όπου απαιτείται.

### Side API (`side_api`)
- Αποτελεί τον client προς ΣΗΔΕ/Docutracks για login, αποστολή σχεδίου, ανάκτηση metadata και download παραγόμενων αρχείων.
- Το αποτέλεσμα των κλήσεων αποτυπώνεται και στο `field_plan_dt_api_response` ώστε να υπάρχει operational trace μέσα στο incoming.

### Side polling (`side_polling`)
- Τρέχει μέσω `cron` και εκτελεί due jobs για plan initial / correction handlers.
- Παρακολουθεί την κατάσταση σχεδίων που έχουν σταλεί στη ΣΗΔΕ, ενημερώνει το incoming και τροφοδοτεί downstream notifications/activity.

### Activities Mods (`activities_mods`)
- Αντικαθιστά/επεκτείνει το activity logging μόνο όπου χρειάζεται για `incoming`.
- Καταγράφει αλλαγές πεδίων και moderation transitions ώστε το audit trail να είναι πιο χρήσιμο επιχειρησιακά από ένα generic entity log.

### Incoming Notifications (`incoming_notifications`)
- Μετατρέπει τα moderation state changes και τα remarks σε email/platform notifications.
- Συνδυάζεται με `field_notifications` για να εφαρμόζει προτίμηση delivery ανά χρήστη.

### Incoming Views Tweaks (`incoming_views_tweaks`)
- Εφαρμόζει role-based scoping στις λίστες incoming.
- Ο `Χειριστής` βλέπει κυρίως τα δικά του assignments, ο `Προϊστάμενος Τμήματος` τα δικά του supervisor assignments, ενώ `Γραμματεία`, `Προϊστάμενος Διεύθυνσης` και `Διαχείριστής ΚΕΜΚΕ` έχουν ευρύτερη ορατότητα.

### AMKE incoming access by reference (`amke_access_incoming_by_ref`)
- Επιβάλλει access filtering για `Χρήστης ΑΜΚΕ` με βάση το `field_legal_entity` του incoming και του profile.
- Επηρεάζει τόσο canonical access όσο και Views queries, ώστε το access model να είναι συνεπές σε σελίδες και λίστες.

## 3. Ροή δεδομένων Drupal -> ΣΗΔΕ

```text
Χρήστης / API client
        |
        v
Drupal incoming form / incoming_api
        |
        v
Node incoming + files + paragraphs + workflow state
        |
        +--> activities_mods -> activity log
        +--> incoming_notifications -> email / platform notifications
        |
        v
side_api (Docutracks client)
        |
        v
ΣΗΔΕ / Docutracks
        |
        v
side_polling (cron) / incoming_plan_correction
        |
        v
Ενημέρωση Drupal incoming
        |
        +--> field_plan_dt_api_response
        +--> field_notes / field_plan_signed / state changes
        +--> activities_mods / incoming_notifications
```

## 4. Λίστα ενεργών custom modules

| Module | Σύντομη περιγραφή |
| --- | --- |
| Activities Mods (`activities_mods`) | Επεκτείνει το activity log για τα `incoming` με ανίχνευση αλλαγών field-by-field και moderation transitions. |
| Admin Theme Per Role (`admin_theme_per_role`) | Εφαρμόζει admin theme σε επιλεγμένο role μόνο για admin-like πρόσβαση. |
| AMKE incoming access by reference (`amke_access_incoming_by_ref`) | Επιβάλλει access filtering για `Χρήστης ΑΜΚΕ` με βάση το `field_legal_entity` στα `incoming`. |
| AMKE tweaks (`amke_tweaks`) | Τροποποιεί τη φόρμα και τη ροή εργασίας του `Χρήστης ΑΜΚΕ` στα `incoming`. |
| Case Path Titles (`case_path_titles`) | Εμπλουτίζει τους τίτλους σελίδων για υποθέσεις/εισερχόμενα. |
| Case Tweaks (`case_tweaks`) | Παράγει και συντηρεί το `field_ref_id` για taxonomy terms `case`. |
| Case XLS Export (`case_xls_export`) | Βοηθητικά για εξαγωγές Views XLS με ιεραρχία υποθέσεων. |
| Checkbox radios (`checkbox_radios`) | Μετατρέπει επιλεγμένα checkbox widgets σε single-select συμπεριφορά. |
| Custom permissions access (`custom_permissions_access`) | Δίνει ελεγχόμενη πρόσβαση στη σελίδα permissions για operational admin role. |
| Datetime Optional Time (`datetime_optional_time`) | Formatter/UX βελτίωση για datetime fields με προαιρετικό time μέρος. |
| Edit Settings (`edit_settings`) | Εσωτερικές φόρμες επεξεργασίας ρυθμίσεων και manuals. |
| Field non-empty lock (`field_non_empty_lock`) | Κλειδώνει ή κρύβει πεδία όταν συμπληρωθούν, ώστε να προστατεύεται το workflow data model. |
| Greek Holidays (`greek_holidays`) | Entity και utilities για ελληνικές αργίες, χρήσιμα σε deadline logic και reports. |
| Incoming API (`incoming_api`) | JSON API για δημιουργία `incoming` και μεταφόρτωση αρχείων από εξωτερικά συστήματα. |
| Incoming change state (`incoming_change_state`) | Τοπική φόρμα αλλαγής moderation state για `incoming`. |
| Incoming create/select case (`incoming_create_select_case`) | Γρήγορη δημιουργία/επιλογή υπόθεσης μέσα από τη φόρμα incoming. |
| Incoming Edit Tweaks (`incoming_edit_tweaks`) | Redirect και usability tweaks μετά από save/edit σε incoming. |
| Incoming form validations (`incoming_form_validations`) | Client-side και form-level validations για incoming workflow. |
| Incoming Notifications (`incoming_notifications`) | Στέλνει email και platform notifications με βάση state changes και remarks. |
| Incoming plan correction (`incoming_plan_correction`) | Ροή αποστολής διορθωμένου σχεδίου προς ΣΗΔΕ/Docutracks για ολοκληρωμένα incoming. |
| Incoming Related Field (`incoming_related_field`) | Διαχειρίζεται τις σχέσεις με άλλα incoming και κανόνες σχετικών εγγράφων. |
| Incoming Remarks (`incoming_remarks`) | Διαχειρίζεται metadata, ορατότητα και συμπεριφορά remark paragraphs. |
| Incoming state validations (`incoming_state_validations`) | Ελέγχει business rules πριν από transitions του workflow incoming. |
| Incoming tweaks (`incoming_tweaks`) | Μικρές λειτουργικές προσαρμογές στη φόρμα incoming και στη διασύνδεση Docutracks. |
| Incoming Views PDF Tweaks (`incoming_views_pdf_tweaks`) | Προσαρμογές στις PDF εξαγωγές των incoming Views. |
| Incoming Views Tweaks (`incoming_views_tweaks`) | Περιορίζει τα αποτελέσματα λιστών incoming ανά ρόλο και assignment. |
| Kemke Breadcrumbs (`kemke_breadcrumbs`) | Κεντρική παραγωγή breadcrumb για υποθέσεις και incoming. |
| KEMKE GSIS PA OAuth2 Client (`kemke_gsis_pa_oauth2_client`) | Custom `oauth2_client` plugin για oAuth2.0.PA και logging κλήσεων GSIS PA. |
| KEMKE Manuals (`kemke_manuals`) | Role-based σελίδα manuals/help υλικού. |
| Kemke Reports (`kemke_reports`) | Reports και υπολογισμοί KPI πάνω στα `incoming`. |
| KEMKE Users GSIS PA Auth2 (`kemke_users_gsis_pa_auth2`) | Glue logic για σύνδεση μέσω GSIS PA, αντιστοίχιση σε local user και sync επιλεγμένων profile fields. |
| Node Edit Concurrency Warning (`node_edit_concurrency_warning`) | Προειδοποίηση όταν φόρμα edit έχει γίνει stale. |
| Operators reference view (`operators_reference_view`) | View query alterations για στοιχεία απουσιών χειριστών. |
| Opinion Reference ID Tweaks (`opinion_ref_id_tweaks`) | Παραγωγή και ρυθμίσεις για opinion reference ids. |
| Permissions filtering (`permissions_filtering`) | Κρύβει μη επιχειρησιακά permission sets από το UI διαχείρισης. |
| Read-only Admin Simulator (`readonly_admin_simulator`) | Read-only προσομοίωση admin-like role για παρατηρητή. |
| Save Draft (`save_draft`) | Client-side αποθήκευση/φόρτωση draft για incoming φόρμα. |
| Select2 Level Class (`select2_level_class`) | CSS classes βάθους σε Select2 options για nested taxonomies. |
| Side API (`side_api`) | Client integration προς ΣΗΔΕ/Docutracks για login, register, fetch και download αρχείων. |
| Side polling (`side_polling`) | Cron/polling manager που παρακολουθεί την εξέλιξη σχεδίων και ενημερώνει το Drupal. |
| Taxonomy tweaks (`taxonomy_tweaks`) | Χρήσιμες προσαρμογές σε taxonomy forms και behaviors. |
| User Import (`user_import`) | Drush import χρηστών από CSV/TSV. |
| User Pending Role Notice (`user_pending_role_notice`) | Notice/redirect για users που δεν έχουν ακόμη operational role. |
| Users Tweaks (`users_tweaks`) | Προσαρμογές στο user profile, redirects, user form και sync με Docutracks στοιχεία. |
| Views Entity Reference Select2 (`views_entity_reference_select2`) | Select2 entity-reference φίλτρα πάνω σε Views displays. |
| Views Year Filters (`views_year_filters`) | Επαναχρησιμοποιήσιμα Views filters για date-by-year αναζήτηση. |

## 5. RBAC, OAuth2 και activity log

- RBAC: ο βασικός κορμός είναι το exported Drupal role configuration. Για συγκεκριμένα user fields χρησιμοποιείται το `field_permissions`, ενώ για τα `incoming` εφαρμόζονται και dynamic restrictions ανά ρόλο/state/assignment.
- OAuth2.0.PA: η ροή περνά από `kemke_gsis_pa_oauth2_client` και `kemke_users_gsis_pa_auth2`. Το `ΑΦΜ` και το `userid` λειτουργούν ως lookup keys για local account matching. Δεν υπάρχει στο codebase ξεχωριστός πίνακας κανόνων `ΑΦΜ -> role`. Η επιτυχής σύνδεση μέσω ΚΔΔ δεν αρκεί από μόνη της για επιχειρησιακή πρόσβαση: ο ρόλος του χρήστη στην πλατφόρμα πρέπει να έχει αποδοθεί από `kemke_admin`.
- Activity log: το `activities_mods` συνθέτει human-readable change sets για τα `incoming` και κρατά χρήσιμο ιστορικό moderation και field μεταβολών.

# Επισκόπηση σχήματος βάσης

- Generated: 2026-04-03 06:38:29 UTC
- Scope: custom bundles και custom fields
- Focus: content types, taxonomies, user entity, field-level access

> Σημείωση ορολογίας: Η ονομασία `incoming` (content type) αρχικά σήμαινε "εισερχόμενα έγγραφα". Στην πορεία της ανάπτυξης της πλατφόρμας χρησιμοποιήθηκε πρακτικά με την ευρύτερη έννοια "έγγραφο/έγγραφα". Επομένως, όπου εμφανίζεται ο όρος `incoming` ως αναφορά ή σε όνομα custom module, στο παρόν κείμενο διαβάζεται ως "έγγραφο/έγγραφα".

## Περιεχόμενο

1. Σχήμα περιεχομένου
2. Λεξιλόγια taxonomy
3. Οντότητα χρήστη
4. Πίνακες πρόσβασης ανά field και ρόλο

---

## 1. Σχήμα περιεχομένου

### Contact (`contact`)
- Σκοπός: Επαφές που συγχρονίζονται από payload αποστολέα Docutracks.
- Πεδία:
  - Docutracks ID (`field_dt_contact_id`): Αριθμός (ακέραιος) | προαιρετικό | πληθικότητα: 1
  - Email (`field_dt_email`): Email | προαιρετικό | πληθικότητα: 1
  - JSON (`field_dt_doc_apostoleas_json`): Κείμενο (απλό, μακρύ) | προαιρετικό | πληθικότητα: 1
  - Επωνυμία (`field_dt_lastname`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - Περιβάλλον (`field_dt_env`): Λίστα (κείμενο) | προαιρετικό | πληθικότητα: 1
  - Φορέας (`field_legal_entity`): Αναφορά οντότητας | προαιρετικό | πληθικότητα: unlimited | αναφορά σε taxonomy_term -> legal_entity

### Έγγραφο (`incoming`)
- Σκοπός: Κεντρική οντότητα εισερχομένων εγγράφων και workflow επεξεργασίας.
- Πεδία:
  - Docutracks DocId (`field_dt_docid`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - Kατάσταση (`field_amke_status`): Λίστα (κείμενο) | προαιρετικό | πληθικότητα: 1
  - primary (`field_primary`): Δυαδική τιμή | προαιρετικό | πληθικότητα: 1
  - Ref ID (`field_ref_id`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - SA number (`field_sa_number`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - Sani user (`field_sani_user`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - TAA project (`field_taa_project`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - Thematic unit (`field_thematic_unit`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - Transparency Requirement (`field_transparency_requirement`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - Α.Π. Αποστολέα (`field_protocol_number_sender`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - Α.Π. Εισερχομένου (`field_protocol_number_doc`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - Αιτιολογία παράκαμψης (`field_opinion_number_bypass`): Κείμενο (απλό, μακρύ) | προαιρετικό | πληθικότητα: 1
  - Αιτούμενη προθεσμία (`field_requested_deadline`): Ημερομηνία | προαιρετικό | πληθικότητα: 1
  - Ακριβες Αντίγραφο (`field_plan_signed`): Αρχείο | προαιρετικό | πληθικότητα: unlimited
  - Απάντηση docutracks  (`field_plan_dt_api_response`): Κείμενο (απλό, μακρύ) | προαιρετικό | πληθικότητα: 1
  - Αποστολέας (`field_sender`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - Αριθμός γνωμοδότησης (`field_opinion_ref_id`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - Αριθμός εργάσιμων ημερών  (`field_working_days`): Αριθμός (ακέραιος) | προαιρετικό | πληθικότητα: 1
  - Αρχεία (`field_documents`): Entity reference revisions | προαιρετικό | πληθικότητα: unlimited | αναφορά σε paragraph -> documents
  - Βασικός χειριστής (`field_basic_operator`): Αναφορά οντότητας | προαιρετικό | πληθικότητα: 1 | αναφορά σε user
  - Είδος εγγράφου (`field_incoming_type`): Αναφορά οντότητας | προαιρετικό | πληθικότητα: 1 | αναφορά σε taxonomy_term -> incoming_type
  - Εμπρόθεσμες Υποθέσεις (`field_on_time_cases`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - Εμπρόθεσμο Στόχος 1 (`field_on_time_obj1`): Λίστα (κείμενο) | προαιρετικό | πληθικότητα: 1
  - Εμπρόθεσμο Στόχος 2 (`field_on_time_obj2`): Λίστα (κείμενο) | προαιρετικό | πληθικότητα: 1
  - Εμπρόθεσμο Στόχος 3 (`field_on_time_obj3`): Λίστα (κείμενο) | προαιρετικό | πληθικότητα: 1
  - Εμπρόθεσμο Στόχος 5 (`field_on_time_obj5`): Λίστα (κείμενο) | προαιρετικό | πληθικότητα: 1
  - Ενδιάμεση προθεσμία (`field_interim_deadline`): Ημερομηνία | προαιρετικό | πληθικότητα: 1
  - Ημέρες εργασίας απο πληρότητα (`field_days_from_fullness_check`): Αριθμός (ακέραιος) | προαιρετικό | πληθικότητα: 1
  - Ημερομηνία Πρωτοκόλλησης (`field_protocol_date`): Ημερομηνία | προαιρετικό | πληθικότητα: 1
  - Ημερομηνία έναρξης (`field_start_date`): Ημερομηνία | προαιρετικό | πληθικότητα: 1
  - Ημερομηνία εισόδου (`field_entry_date`): Ημερομηνία | προαιρετικό | πληθικότητα: 1
  - Ημερομηνία ολοκλήρωσης (`field_completion_date`): Ημερομηνία | προαιρετικό | πληθικότητα: 1
  - Ημερομηνία παράτασης (`field_extension_date`): Ημερομηνία | προαιρετικό | πληθικότητα: 1
  - Ημερομηνία πληρότητας (`field_fullness_check_date`): Ημερομηνία | προαιρετικό | πληθικότητα: 1
  - Ημερομηνία υπογραφής απόρριψης (`field_signature_rejection_date`): Ημερομηνία | προαιρετικό | πληθικότητα: 1
  - Θέμα (`field_subject`): Text (formatted, long) | υποχρεωτικό | πληθικότητα: 1
  - Λέξεις κλειδιά (`field_tags`): Αναφορά οντότητας | προαιρετικό | πληθικότητα: unlimited | αναφορά σε taxonomy_term -> tags
  - Νόμιμη προθεσμία (`field_legal_deadline`): Ημερομηνία | προαιρετικό | πληθικότητα: 1
  - Παράταση (`field_extension`): Δυαδική τιμή | προαιρετικό | πληθικότητα: 1
  - Παρατηρήσεις (`field_remarks`): Entity reference revisions | προαιρετικό | πληθικότητα: unlimited | αναφορά σε paragraph -> remark
  - Προτεραιότητα (`field_priority`): Αναφορά οντότητας | προαιρετικό | πληθικότητα: 1 | αναφορά σε taxonomy_term -> priority
  - Προϊστάμενος τμήματος (`field_supervisor`): Αναφορά οντότητας | προαιρετικό | πληθικότητα: 1 | αναφορά σε user
  - Συνημμένα Αρχεία (`field_attachments`): Αρχείο | προαιρετικό | πληθικότητα: unlimited
  - Συνολικές Υποθέσεις (`field_total_cases`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - Σχέδιο (`field_plan`): Αρχείο | προαιρετικό | πληθικότητα: unlimited
  - Σχόλιο απο ΣΗΔΕ (`field_notes`): Κείμενο (απλό, μακρύ) | προαιρετικό | πληθικότητα: 1
  - Σύνδεση με άλλα εισερχόμενα (`field_related_incoming`): Αναφορά οντότητας | προαιρετικό | πληθικότητα: unlimited | αναφορά σε node -> incoming
  - Υπογραφή Απόρριψη (`field_signature_rejection`): Λίστα (κείμενο) | προαιρετικό | πληθικότητα: unlimited
  - Υποκατηγορία εγγράφου (`field_incoming_subtype`): Αναφορά οντότητας | προαιρετικό | πληθικότητα: unlimited | αναφορά σε taxonomy_term -> incoming_subtype
  - Υπόθεση (`field_case`): Αναφορά οντότητας | προαιρετικό | πληθικότητα: unlimited | αναφορά σε taxonomy_term -> case — Προαιρετική αναφορά σε μία ή περισσότερες σχετικές υπόθεσεις
  - Φορέας (`field_legal_entity`): Αναφορά οντότητας | προαιρετικό | πληθικότητα: unlimited | αναφορά σε taxonomy_term -> legal_entity
  - Χειριστές (`field_operators`): Αναφορά οντότητας | προαιρετικό | πληθικότητα: unlimited | αναφορά σε user
  - Χρεωμένο (`field_assignees`): Κείμενο (απλό, μακρύ) | προαιρετικό | πληθικότητα: 1
  - απάντηση (`field_answer`): Text (formatted, long) | προαιρετικό | πληθικότητα: 1
  - αρχεία απάντησης (`field_answer_files`): Αρχείο | προαιρετικό | πληθικότητα: unlimited

### Αποθετήριο (`repository_item`)
- Σκοπός: Στοιχεία αποθετηρίου με αρχεία και κατηγοριοποίηση.
- Πεδία:
  - Αρχεία (`field_repository_files`): Αρχείο | προαιρετικό | πληθικότητα: unlimited
  - Ημερομηνία (`field_repository_date`): Ημερομηνία | προαιρετικό | πληθικότητα: 1
  - Περιγραφή (`field_repository_description`): Text (formatted, long) | προαιρετικό | πληθικότητα: 1
  - Τύπος αποθετηρίου (`field_repository_type`): Αναφορά οντότητας | προαιρετικό | πληθικότητα: 1 | αναφορά σε taxonomy_term -> repository_type

---

## 2. Λεξιλόγια taxonomy

### Υπόθεση (`case`)
- Σκοπός: Λεξιλόγιο υποθέσεων.
- Πεδία:
  - Ref ID (`field_ref_id`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - Τιτλος (`field_name_typed`): Κείμενο (απλό) | υποχρεωτικό | πληθικότητα: 1

### Υποκατηγορία εγγράφου (`incoming_subtype`)
- Σκοπός: Λεξιλόγιο υποκατηγοριών εγγράφου.
- Πεδία: δεν υπάρχουν custom fields.

### Κατηγορία εγγράφου (`incoming_type`)
- Σκοπός: Λεξιλόγιο κατηγοριών εγγράφου.
- Πεδία: δεν υπάρχουν custom fields.

### Φορέας (`legal_entity`)
- Σκοπός: Λεξιλόγιο φορέων.
- Πεδία: δεν υπάρχουν custom fields.

### Προτεραιότητα (`priority`)
- Σκοπός: Λεξιλόγιο προτεραιοτήτων.
- Πεδία: δεν υπάρχουν custom fields.

### Τύπος αποθετηρίου (`repository_type`)
- Σκοπός: Κατηγορίες για τα στοιχεία του αποθετηρίου.
- Πεδία: δεν υπάρχουν custom fields.

### Λέξεις κλειδιά (`tags`)
- Σκοπός: Λεξιλόγιο λέξεων-κλειδιών.
- Πεδία: δεν υπάρχουν custom fields.

---

## 3. Οντότητα χρήστη

- Πεδία:
  - Docutracks config (`field_dt_config`): Κείμενο (απλό, μακρύ) | προαιρετικό | πληθικότητα: 1
  - Docutracks email (`field_docutracks_email`): Email | προαιρετικό | πληθικότητα: 1
  - Docutracks ID (`field_docutracks_id`): Αριθμός (ακέραιος) | προαιρετικό | πληθικότητα: 1
  - Docutracks JSON (`field_docutracks_json`): Κείμενο (απλό, μακρύ) | προαιρετικό | πληθικότητα: 1
  - Docutracks username (`field_docutracks_username`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - Last Password Reset (`field_last_password_reset`): Ημερομηνία | προαιρετικό | πληθικότητα: 1
  - Password Expiration (`field_password_expiration`): Δυαδική τιμή | προαιρετικό | πληθικότητα: 1 — Control whether the user must reset their password. If the password has expired, this field is automatically checked after the execution of Cron.
  - Pending Expiration Mail Count (`field_pending_expire_sent`): Αριθμός (ακέραιος) | προαιρετικό | πληθικότητα: 1 — Whether an email notifying of a pending password expiration has been sent
  - userid (`field_gsis_userid`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - Άδειες (`field_absenses_ref`): Entity reference revisions | προαιρετικό | πληθικότητα: unlimited | αναφορά σε paragraph -> absenses
  - Όνομα (`field_first_name`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - ΑΦΜ (`field_gsis_afm`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - Αλλαγές καστάστασης (`field_access_change_log`): Κείμενο (απλό, μακρύ) | προαιρετικό | πληθικότητα: 1
  - Αύξων αριθμός (`field_serial_number`): Αριθμός (ακέραιος) | προαιρετικό | πληθικότητα: 1
  - Εικόνα (`user_picture`): Εικόνα | προαιρετικό | πληθικότητα: 1 — Το εικονικό σας πρόσωπο ή εικόνα.
  - Επιλογή Ειδοποιήσεων (`field_notifications`): Λίστα (κείμενο) | υποχρεωτικό | πληθικότητα: 1
  - Επώνυμο (`field_last_name`): Κείμενο (απλό) | προαιρετικό | πληθικότητα: 1
  - Πληροφορίες χρήστη (`field_gsis_info`): Κείμενο (απλό, μακρύ) | προαιρετικό | πληθικότητα: 1
  - Προσωρινά ανενεργός χρήστης (`field_temporary_disabled`): Δυαδική τιμή | προαιρετικό | πληθικότητα: 1
  - Τελευταία αλλαγή (`field_last_access_change`): Ημερομηνία | προαιρετικό | πληθικότητα: 1
  - Φορέας (`field_legal_entity`): Αναφορά οντότητας | προαιρετικό | πληθικότητα: 1 | αναφορά σε taxonomy_term -> legal_entity

---

## 4. Πίνακες πρόσβασης ανά field και ρόλο

- Υπόμνημα: `Π` = προβολή, `Ε` = επεξεργασία, `ιδ.` = μόνο own content/profile, `*` = ισχύουν πρόσθετοι programmatic περιορισμοί.
- Οι πίνακες συνδυάζουν γενικά permissions, `field_permissions` όπου υπάρχει, και programmatic restrictions που εντοπίστηκαν στα custom modules.

### Contact (`contact`)

| Field | Προϊστάμενος Διεύθυνσης | Προϊστάμενος Τμήματος | Γραμματεία | Χρήστης ΑΜΚΕ | Χειριστής | Διαχείριστής ΚΕΜΚΕ | Παρατηρήσεις |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Docutracks ID (`field_dt_contact_id`) | Π/Ε | Π/Ε | Π/Ε | Π | Π/Ε | Π/Ε | - |
| Email (`field_dt_email`) | Π/Ε | Π/Ε | Π/Ε | Π | Π/Ε | Π/Ε | - |
| JSON (`field_dt_doc_apostoleas_json`) | Π/Ε | Π/Ε | Π/Ε | Π | Π/Ε | Π/Ε | - |
| Επωνυμία (`field_dt_lastname`) | Π/Ε | Π/Ε | Π/Ε | Π | Π/Ε | Π/Ε | - |
| Περιβάλλον (`field_dt_env`) | Π/Ε | Π/Ε | Π/Ε | Π | Π/Ε | Π/Ε | - |
| Φορέας (`field_legal_entity`) | Π/Ε | Π/Ε | Π/Ε | Π | Π/Ε | Π/Ε | - |

### Έγγραφο (`incoming`)

| Field | Προϊστάμενος Διεύθυνσης | Προϊστάμενος Τμήματος | Γραμματεία | Χρήστης ΑΜΚΕ | Χειριστής | Διαχείριστής ΚΕΜΚΕ | Παρατηρήσεις |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Docutracks DocId (`field_dt_docid`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Kατάσταση (`field_amke_status`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| primary (`field_primary`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Ref ID (`field_ref_id`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | Κρύβεται όταν είναι κενό σε edit και δεν εμφανίζεται ως editable field σε όλα τα στάδια. |
| SA number (`field_sa_number`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Sani user (`field_sani_user`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| TAA project (`field_taa_project`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Thematic unit (`field_thematic_unit`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Transparency Requirement (`field_transparency_requirement`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Α.Π. Αποστολέα (`field_protocol_number_sender`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | Conditional UI access. Κρύβεται όταν υπάρχει ήδη primary document ή όταν το state δεν επιτρέπει αλλαγή. |
| Α.Π. Εισερχομένου (`field_protocol_number_doc`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Αιτιολογία παράκαμψης (`field_opinion_number_bypass`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Αιτούμενη προθεσμία (`field_requested_deadline`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Ακριβες Αντίγραφο (`field_plan_signed`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | Κρύβεται όταν είναι κενό και πριν την παραγωγή signed copy. |
| Απάντηση docutracks  (`field_plan_dt_api_response`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | Τεχνικό/diagnostic log πεδίο για διασύνδεση Docutracks. Κρύβεται όταν είναι κενό. |
| Αποστολέας (`field_sender`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Αριθμός γνωμοδότησης (`field_opinion_ref_id`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Αριθμός εργάσιμων ημερών  (`field_working_days`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Αρχεία (`field_documents`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | Conditional UI access. Για ΚΕΜΚΕ ρόλους εμφανίζεται κυρίως στη δημιουργία ή μέχρι να υπάρχει primary document. Για Χρήστη ΑΜΚΕ εμφανίζεται στη δημιουργία και στο state `temp` όσο δεν υπάρχει primary document. |
| Βασικός χειριστής (`field_basic_operator`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | Programmatic locking για Χειριστής μετά την αρχική συμπλήρωση. |
| Είδος εγγράφου (`field_incoming_type`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Εμπρόθεσμες Υποθέσεις (`field_on_time_cases`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Εμπρόθεσμο Στόχος 1 (`field_on_time_obj1`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Εμπρόθεσμο Στόχος 2 (`field_on_time_obj2`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Εμπρόθεσμο Στόχος 3 (`field_on_time_obj3`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Εμπρόθεσμο Στόχος 5 (`field_on_time_obj5`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Ενδιάμεση προθεσμία (`field_interim_deadline`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | Programmatic locking για Χρήστη ΑΜΚΕ σε edit από `field_non_empty_lock`. |
| Ημέρες εργασίας απο πληρότητα (`field_days_from_fullness_check`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Ημερομηνία Πρωτοκόλλησης (`field_protocol_date`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Ημερομηνία έναρξης (`field_start_date`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Ημερομηνία εισόδου (`field_entry_date`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Ημερομηνία ολοκλήρωσης (`field_completion_date`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Ημερομηνία παράτασης (`field_extension_date`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Ημερομηνία πληρότητας (`field_fullness_check_date`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Ημερομηνία υπογραφής απόρριψης (`field_signature_rejection_date`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Θέμα (`field_subject`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | Programmatic locking από `field_non_empty_lock`. Για Προϊστάμενος Διεύθυνσης και Προϊστάμενος Τμήματος κλειδώνει μετά την πρώτη τιμή. Για Χειριστής κλειδώνει σε edit. Για Χρήστη ΑΜΚΕ γίνεται read-only εκτός `temp`. |
| Λέξεις κλειδιά (`field_tags`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Νόμιμη προθεσμία (`field_legal_deadline`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Παράταση (`field_extension`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Παρατηρήσεις (`field_remarks`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | Για Χρήστη ΑΜΚΕ κρύβεται όταν το incoming βρίσκεται σε `temp`. |
| Προτεραιότητα (`field_priority`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Προϊστάμενος τμήματος (`field_supervisor`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | Programmatic locking για Προϊστάμενος Τμήματος και Χειριστής μετά την αρχική συμπλήρωση. |
| Συνημμένα Αρχεία (`field_attachments`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Συνολικές Υποθέσεις (`field_total_cases`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Σχέδιο (`field_plan`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Σχόλιο απο ΣΗΔΕ (`field_notes`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | Σχόλιο από ΣΗΔΕ. Εμφανίζεται κυρίως μετά από ολοκλήρωση διασύνδεσης ή polling. |
| Σύνδεση με άλλα εισερχόμενα (`field_related_incoming`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | Conditional UI access. Για ΚΕΜΚΕ ρόλους εμφανίζεται όταν υπάρχει primary document. Για Χρήστη ΑΜΚΕ χρησιμοποιείται κυρίως στο `temp` όταν υπάρχει primary document. |
| Υπογραφή Απόρριψη (`field_signature_rejection`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Υποκατηγορία εγγράφου (`field_incoming_subtype`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Υπόθεση (`field_case`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| Φορέας (`field_legal_entity`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | Για Χρήστη ΑΜΚΕ δεν αποτελεί βασικό editable field της φόρμας. Συμπληρώνεται αυτόματα από το profile μέσω `amke_tweaks` και χρησιμοποιείται επιπλέον από `amke_access_incoming_by_ref` για view/update filtering. |
| Χειριστές (`field_operators`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | Programmatic locking για Χειριστής μετά την αρχική συμπλήρωση. |
| Χρεωμένο (`field_assignees`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| απάντηση (`field_answer`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |
| αρχεία απάντησης (`field_answer_files`) | Π/Ε* | Π/Ε* | Π/Ε* | Π/Ε ιδ.* | Π/Ε* | Π/Ε* | - |

Πρόσθετες παρατηρήσεις για `incoming`:
- Το `amke_access_incoming_by_ref` περιορίζει `view` και `update` για `Χρήστης ΑΜΚΕ` όταν το `field_legal_entity` του εισερχομένου δεν ταιριάζει με το profile του χρήστη.
- Το `incoming_views_tweaks` περιορίζει τις λίστες: ο `Χειριστής` βλέπει τα εισερχόμενα όπου είναι `field_operators` ή `field_basic_operator`, ενώ ο `Προϊστάμενος Τμήματος` όσα τον έχουν στο `field_supervisor`.
- Το `field_non_empty_lock` κλειδώνει συγκεκριμένα fields όταν αποκτήσουν τιμή, ώστε να αποφεύγονται μεταγενέστερες αλλοιώσεις workflow δεδομένων.
- Το `amke_tweaks` αλλάζει programmatically τη φόρμα του `Χρήστης ΑΜΚΕ` ανάλογα με το state (`temp`, `draft`, `pending_issues`) και το αν υπάρχει primary document.

### Αποθετήριο (`repository_item`)

| Field | Προϊστάμενος Διεύθυνσης | Προϊστάμενος Τμήματος | Γραμματεία | Χρήστης ΑΜΚΕ | Χειριστής | Διαχείριστής ΚΕΜΚΕ | Παρατηρήσεις |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Αρχεία (`field_repository_files`) | Π/Ε | Π/Ε | Π/Ε | Π | Π/Ε | Π/Ε | - |
| Ημερομηνία (`field_repository_date`) | Π/Ε | Π/Ε | Π/Ε | Π | Π/Ε | Π/Ε | - |
| Περιγραφή (`field_repository_description`) | Π/Ε | Π/Ε | Π/Ε | Π | Π/Ε | Π/Ε | - |
| Τύπος αποθετηρίου (`field_repository_type`) | Π/Ε | Π/Ε | Π/Ε | Π | Π/Ε | Π/Ε | - |

### Custom user fields με `field_permissions`

| Field | Προϊστάμενος Διεύθυνσης | Προϊστάμενος Τμήματος | Γραμματεία | Χρήστης ΑΜΚΕ | Χειριστής | Διαχείριστής ΚΕΜΚΕ | Παρατηρήσεις |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Επιλογή Ειδοποιήσεων (`field_notifications`) | Π/Ε ιδ. | Π/Ε ιδ. | Π/Ε ιδ. | Π/Ε ιδ. | Π/Ε ιδ. | Π/Ε | Explicit `field_permissions`. Όλοι οι operational roles έχουν `edit own field_notifications`. Το `kemke_admin` έχει και global `edit field_notifications` και `create field_notifications`. |
| Προσωρινά ανενεργός χρήστης (`field_temporary_disabled`) | Π/Ε | Π | Π/Ε | Π | Π | Π/Ε | Explicit `field_permissions`. Προβολή από όλους τους authenticated users. Επεξεργασία μόνο από `Προϊστάμενος Διεύθυνσης`, `Γραμματεία`, `Διαχείριστής ΚΕΜΚΕ`. |
| Αύξων αριθμός (`field_serial_number`) | Π/Ε | Π/Ε | Π/Ε | Π | Π | Π/Ε | Explicit `field_permissions`. Προβολή από όλους τους authenticated users. Επεξεργασία από `Προϊστάμενος Διεύθυνσης`, `Προϊστάμενος Τμήματος`, `Γραμματεία`, `Διαχείριστής ΚΕΜΚΕ`. |
| Φορέας (`field_legal_entity`) | - | - | - | - | - | - | Το storage είναι δηλωμένο με `field_permissions`, αλλά στο exported role config δεν υπάρχει explicit grant για τους operational roles. Επιπλέον το `users_tweaks` το εμφανίζει στο user form μόνο όταν ο target χρήστης έχει role `Χρήστης ΑΜΚΕ`. |
| Docutracks config (`field_dt_config`) | - | - | - | - | - | - | Τεχνικό πεδίο. Δεν βρέθηκε explicit operational permission στο exported role config. |

Συμπέρασμα για user profile access:
- Το `field_permissions` χρησιμοποιείται κυρίως για τα πεδία `field_notifications`, `field_temporary_disabled`, `field_serial_number`, `field_legal_entity`, `field_dt_config`.
- Σε επίπεδο λειτουργικής χρήσης, κρίσιμα operational πεδία είναι κυρίως τα `field_notifications`, `field_temporary_disabled`, `field_serial_number` και το `field_legal_entity` για τους χρήστες `Χρήστης ΑΜΚΕ`.
- Το `incoming_notifications` διαβάζει το `field_notifications` για να αποφασίσει αν κάθε χρήστης λαμβάνει platform notifications, email notifications ή κανένα από τα δύο.

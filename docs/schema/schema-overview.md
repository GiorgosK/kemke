# Schema overview

- Generated: 2026-03-19 11:59:29 UTC
- Scope: custom bundles/fields (Drupal base fields omitted)
- Focus: content types, taxonomies, user fields

---

## Content types

### Contact (`contact`)
- Purpose: Contacts synced from Docutracks Apostoleas payload.
- Fields:
  - Docutracks ID (`field_dt_contact_id`): Αριθμός (ακέραιος) | optional | cardinality: 1
  - Email (`field_dt_email`): Email | optional | cardinality: 1
  - JSON (`field_dt_doc_apostoleas_json`): Κείμενο (απλό, μακρύ) | optional | cardinality: 1
  - Επωνυμία (`field_dt_lastname`): Κείμενο (απλό) | optional | cardinality: 1
  - Περιβάλλον (`field_dt_env`): Λίστα (κείμενο) | optional | cardinality: 1
  - Φορέας (`field_legal_entity`): Αναφορά οντότητας | optional | cardinality: unlimited | references taxonomy_term → legal_entity

### Έγγραφο (`incoming`)
- Purpose: n/a
- Fields:
  - Docutracks DocId (`field_dt_docid`): Κείμενο (απλό) | optional | cardinality: 1
  - Kατάσταση (`field_amke_status`): Λίστα (κείμενο) | optional | cardinality: 1
  - primary (`field_primary`): Δυαδική τιμή | optional | cardinality: 1
  - Ref ID (`field_ref_id`): Κείμενο (απλό) | optional | cardinality: 1
  - SA number (`field_sa_number`): Κείμενο (απλό) | optional | cardinality: 1
  - Sani user (`field_sani_user`): Κείμενο (απλό) | optional | cardinality: 1
  - TAA project (`field_taa_project`): Κείμενο (απλό) | optional | cardinality: 1
  - Thematic unit (`field_thematic_unit`): Κείμενο (απλό) | optional | cardinality: 1
  - Transparency Requirement (`field_transparency_requirement`): Κείμενο (απλό) | optional | cardinality: 1
  - Α.Π. Αποστολέα (`field_protocol_number_sender`): Κείμενο (απλό) | optional | cardinality: 1
  - Α.Π. Εισερχομένου (`field_protocol_number_doc`): Κείμενο (απλό) | optional | cardinality: 1
  - Αιτιολογία παράκαμψης (`field_opinion_number_bypass`): Κείμενο (απλό, μακρύ) | optional | cardinality: 1
  - Αιτούμενη προθεσμία (`field_requested_deadline`): Ημερομηνία | optional | cardinality: 1
  - Ακριβες Αντίγραφο (`field_plan_signed`): Αρχείο | optional | cardinality: unlimited
  - Απάντηση docutracks  (`field_plan_dt_api_response`): Κείμενο (απλό, μακρύ) | optional | cardinality: 1
  - Αποστολέας (`field_sender`): Κείμενο (απλό) | optional | cardinality: 1
  - Αριθμός γνωμοδότησης (`field_opinion_ref_id`): Κείμενο (απλό) | optional | cardinality: 1
  - Αριθμός εργάσιμων ημερών  (`field_working_days`): Αριθμός (ακέραιος) | optional | cardinality: 1
  - Αρχεία (`field_documents`): Entity reference revisions | optional | cardinality: unlimited | references paragraph → documents
  - Βασικός χειριστής (`field_basic_operator`): Αναφορά οντότητας | optional | cardinality: 1 | references user
  - Είδος εγγράφου (`field_incoming_type`): Αναφορά οντότητας | optional | cardinality: 1 | references taxonomy_term → incoming_type
  - Εμπρόθεσμες Υποθέσεις (`field_on_time_cases`): Κείμενο (απλό) | optional | cardinality: 1
  - Εμπρόθεσμο Στόχος 1 (`field_on_time_obj1`): Λίστα (κείμενο) | optional | cardinality: 1
  - Εμπρόθεσμο Στόχος 2 (`field_on_time_obj2`): Λίστα (κείμενο) | optional | cardinality: 1
  - Εμπρόθεσμο Στόχος 3 (`field_on_time_obj3`): Λίστα (κείμενο) | optional | cardinality: 1
  - Εμπρόθεσμο Στόχος 5 (`field_on_time_obj5`): Λίστα (κείμενο) | optional | cardinality: 1
  - Ενδιάμεση προθεσμία (`field_interim_deadline`): Ημερομηνία | optional | cardinality: 1
  - Ημέρες εργασίας απο πληρότητα (`field_days_from_fullness_check`): Αριθμός (ακέραιος) | optional | cardinality: 1
  - Ημερομηνία Πρωτοκόλλησης (`field_protocol_date`): Ημερομηνία | optional | cardinality: 1
  - Ημερομηνία έναρξης (`field_start_date`): Ημερομηνία | optional | cardinality: 1
  - Ημερομηνία εισόδου (`field_entry_date`): Ημερομηνία | optional | cardinality: 1
  - Ημερομηνία ολοκλήρωσης (`field_completion_date`): Ημερομηνία | optional | cardinality: 1
  - Ημερομηνία παράτασης (`field_extension_date`): Ημερομηνία | optional | cardinality: 1
  - Ημερομηνία πληρότητας (`field_fullness_check_date`): Ημερομηνία | optional | cardinality: 1
  - Ημερομηνία υπογραφής απόρριψης (`field_signature_rejection_date`): Ημερομηνία | optional | cardinality: 1
  - Θέμα (`field_subject`): Text (formatted, long) | required | cardinality: 1
  - Λέξεις κλειδιά (`field_tags`): Αναφορά οντότητας | optional | cardinality: unlimited | references taxonomy_term → tags
  - Νόμιμη προθεσμία (`field_legal_deadline`): Ημερομηνία | optional | cardinality: 1
  - Παράταση (`field_extension`): Δυαδική τιμή | optional | cardinality: 1
  - Παρατηρήσεις (`field_remarks`): Entity reference revisions | optional | cardinality: unlimited | references paragraph → remark
  - Προτεραιότητα (`field_priority`): Αναφορά οντότητας | optional | cardinality: 1 | references taxonomy_term → priority
  - Προϊστάμενος τμήματος (`field_supervisor`): Αναφορά οντότητας | optional | cardinality: 1 | references user
  - Συνημμένα Αρχεία (`field_attachments`): Αρχείο | optional | cardinality: unlimited
  - Συνολικές Υποθέσεις (`field_total_cases`): Κείμενο (απλό) | optional | cardinality: 1
  - Σχέδιο (`field_plan`): Αρχείο | optional | cardinality: unlimited
  - Σχόλιο απο ΣΗΔΕ (`field_notes`): Κείμενο (απλό, μακρύ) | optional | cardinality: 1
  - Σύνδεση με άλλα εισερχόμενα (`field_related_incoming`): Αναφορά οντότητας | optional | cardinality: unlimited | references node → incoming
  - Υπογραφή Απόρριψη (`field_signature_rejection`): Λίστα (κείμενο) | optional | cardinality: unlimited
  - Υποκατηγορία εγγράφου (`field_incoming_subtype`): Αναφορά οντότητας | optional | cardinality: unlimited | references taxonomy_term → incoming_subtype
  - Υπόθεση (`field_case`): Αναφορά οντότητας | optional | cardinality: unlimited | references taxonomy_term → case — Προαιρετική αναφορά σε μία ή περισσότερες σχετικές υπόθεσεις
  - Φορέας (`field_legal_entity`): Αναφορά οντότητας | optional | cardinality: unlimited | references taxonomy_term → legal_entity
  - Χειριστές (`field_operators`): Αναφορά οντότητας | optional | cardinality: unlimited | references user
  - Χρεωμένο (`field_assignees`): Κείμενο (απλό, μακρύ) | optional | cardinality: 1
  - απάντηση (`field_answer`): Text (formatted, long) | optional | cardinality: 1
  - αρχεία απάντησης (`field_answer_files`): Αρχείο | optional | cardinality: unlimited

### Αποθετήριο (`repository_item`)
- Purpose: Στοιχεία αποθετηρίου με αρχεία και κατηγοριοποίηση.
- Fields:
  - Αρχεία (`field_repository_files`): Αρχείο | optional | cardinality: unlimited
  - Ημερομηνία (`field_repository_date`): Ημερομηνία | optional | cardinality: 1
  - Περιγραφή (`field_repository_description`): Text (formatted, long) | optional | cardinality: 1
  - Τύπος αποθετηρίου (`field_repository_type`): Αναφορά οντότητας | optional | cardinality: 1 | references taxonomy_term → repository_type

---

## Taxonomies

### Υπόθεση (`case`)
- Purpose: n/a
- Fields:
  - Ref ID (`field_ref_id`): Κείμενο (απλό) | optional | cardinality: 1
  - Τιτλος (`field_name_typed`): Κείμενο (απλό) | required | cardinality: 1

### Υποκατηγορία εγγράφου (`incoming_subtype`)
- Purpose: n/a
- Fields: none (custom fields not configured)

### Κατηγορία εγγράφου (`incoming_type`)
- Purpose: n/a
- Fields: none (custom fields not configured)

### Φορέας (`legal_entity`)
- Purpose: n/a
- Fields: none (custom fields not configured)

### Προτεραιότητα (`priority`)
- Purpose: n/a
- Fields: none (custom fields not configured)

### Τύπος αποθετηρίου (`repository_type`)
- Purpose: Κατηγορίες για τα στοιχεία του αποθετηρίου.
- Fields: none (custom fields not configured)

### Λέξεις κλειδιά (`tags`)
- Purpose: n/a
- Fields: none (custom fields not configured)

---


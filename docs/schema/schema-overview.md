# Schema overview

- Generated: 2025-12-19 09:43:57 UTC
- Scope: custom bundles/fields (Drupal base fields omitted)
- Focus: content types, taxonomies, user fields

---

## Content types

### Εισερχόμενο (`incoming`)
- Purpose: n/a
- Fields:
  - Docutracks DocId (`field_plan_dt_docid`): Κείμενο (απλό) | optional | cardinality: 1
  - Docutracks δοκιμές (`field_plan_dt_tries`): Αριθμός (ακέραιος) | optional | cardinality: 1
  - Kατάσταση (`field_amke_status`): Λίστα (κείμενο) | optional | cardinality: 1
  - primary (`field_primary`): Δυαδική τιμή | optional | cardinality: 1
  - Ref ID (`field_ref_id`): Κείμενο (απλό) | optional | cardinality: 1
  - SA number (`field_sa_number`): Κείμενο (απλό) | optional | cardinality: 1
  - Sani user (`field_sani_user`): Κείμενο (απλό) | optional | cardinality: 1
  - TAA project (`field_taa_project`): Κείμενο (απλό) | optional | cardinality: 1
  - Thematic unit (`field_thematic_unit`): Κείμενο (απλό) | optional | cardinality: 1
  - Transparency Requirement (`field_transparency_requirement`): Κείμενο (απλό) | optional | cardinality: 1
  - Working days (`field_working_days`): Αριθμός (ακέραιος) | optional | cardinality: 1
  - Α.Π. Αποστολέα (`field_protocol_number_sender`): Κείμενο (απλό) | optional | cardinality: 1
  - Α.Π. Εισερχομένου (`field_protocol_number_doc`): Κείμενο (απλό) | optional | cardinality: 1
  - Αιτούμενη προθεσμία (`field_requested_deadline`): Ημερομηνία | optional | cardinality: 1
  - Ακριβες Αντίγραφο (`field_plan_signed`): Αρχείο | optional | cardinality: 1
  - Απάντηση docutracks  (`field_plan_dt_api_response`): Κείμενο (απλό, μακρύ) | optional | cardinality: 1
  - Αποστολέας (`field_sender`): Κείμενο (απλό) | optional | cardinality: 1
  - Αρχεία (`field_documents`): Entity reference revisions | optional | cardinality: unlimited | references paragraph → documents
  - Βασικός χειριστής (`field_basic_operator`): Αναφορά οντότητας | optional | cardinality: 1 | references user
  - Είδος εγγράφου (`field_incoming_type`): Αναφορά οντότητας | optional | cardinality: 1 | references taxonomy_term → incoming_type
  - Ελήφθη απο docutracks (`field_plan_dt_success`): Δυαδική τιμή | optional | cardinality: 1
  - Εμπρόθεσμο (`field_on_time`): Λίστα (κείμενο) | optional | cardinality: 1
  - Ενδιάμεση προθεσμία (`field_interim_deadline`): Ημερομηνία | optional | cardinality: 1
  - Ημερομηνία Πρωτοκόλλησης (`field_protocol_date`): Ημερομηνία | optional | cardinality: 1
  - Ημερομηνία εισόδου (`field_entry_date`): Ημερομηνία | optional | cardinality: 1
  - Ημερομηνία ολοκλήρωσης (`field_completion_date`): Ημερομηνία | optional | cardinality: 1
  - Ημερομηνία πληρότητας (`field_fullness_check_date`): Ημερομηνία | optional | cardinality: 1
  - Θέμα (`field_subject`): Text (formatted, long) | optional | cardinality: 1
  - Λοιπές Σημειώσεις (`field_notes`): Κείμενο (απλό, μακρύ) | optional | cardinality: 1
  - Νόμιμη προθεσμία (`field_legal_deadline`): Ημερομηνία | optional | cardinality: 1
  - Νόμιμη προθεσμία αρχική (`field_legal_deadline_original`): Ημερομηνία | optional | cardinality: 1
  - Νόμιμη προθεσμία με παράταση (`field_legal_deadline_ext`): Ημερομηνία | optional | cardinality: 1
  - Παρατηρήσεις (`field_remarks`): Entity reference revisions | optional | cardinality: unlimited | references paragraph → remark
  - Προτεραιότητα (`field_priority`): Αναφορά οντότητας | optional | cardinality: 1 | references taxonomy_term → priority
  - Προϊστάμενος (`field_supervisor`): Αναφορά οντότητας | optional | cardinality: 1 | references user
  - Συνημμένα Αρχεία (`field_attachments`): Αρχείο | optional | cardinality: unlimited
  - Σχέδιο (`field_plan`): Αρχείο | optional | cardinality: 1
  - Σύνδεση με άλλα εισερχόμενα (`field_related_incoming`): Αναφορά οντότητας | optional | cardinality: unlimited | references node → incoming
  - Υπόθεση (`field_case`): Αναφορά οντότητας | optional | cardinality: unlimited | references taxonomy_term → case — Προαιρετική αναφορά σε μία ή περισσότερες σχετικές υπόθεσεις
  - Φορέας (`field_legal_entity`): Αναφορά οντότητας | optional | cardinality: 1 | references taxonomy_term → legal_entity
  - Φορέας Ευθύνης (`field_responsible_entity`): Αναφορά οντότητας | optional | cardinality: 1 | references taxonomy_term → responsible_entity
  - Χειριστές (`field_operators`): Αναφορά οντότητας | optional | cardinality: unlimited | references user
  - Χρεωμένο (`field_assignees`): Κείμενο (απλό, μακρύ) | optional | cardinality: 1

---

## Taxonomies

### Υπόθεση (`case`)
- Purpose: n/a
- Fields:
  - Ref ID (`field_ref_id`): Κείμενο (απλό) | optional | cardinality: 1
  - Τιτλος (`field_name_typed`): Κείμενο (απλό) | required | cardinality: 1

### Incoming type (`incoming_type`)
- Purpose: n/a
- Fields: none (custom fields not configured)

### Φορέας (`legal_entity`)
- Purpose: n/a
- Fields: none (custom fields not configured)

### Priority (`priority`)
- Purpose: n/a
- Fields: none (custom fields not configured)

### Responsible Entity (`responsible_entity`)
- Purpose: n/a
- Fields: none (custom fields not configured)

### Ετικέτες (`tags`)
- Purpose: Χρησιμοποιήστε ετικέτες για να ομαδοποιήσετε τα άρθρα σε κατηγορίες με παρόμοιο θέμα.
- Fields: none (custom fields not configured)

---


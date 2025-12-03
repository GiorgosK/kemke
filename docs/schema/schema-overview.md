# Schema overview

- Generated: 2025-12-03 11:47:29 UTC
- Scope: custom bundles/fields (Drupal base fields omitted)
- Focus: content types, taxonomies, user fields

---

## Content types

### Άρθρο (`article`)
- Purpose: Χρησιμοποιήστε τα <em>άρθρα</em> για ευαίσθητο στο χρόνο περιεχόμενο όπως νέα, δελτία τύπου ή ιστολογήματα.
- Fields:
  - Εικόνα (`field_image`): Εικόνα | optional | cardinality: 1
  - Ετικέτες (`field_tags`): Αναφορά οντότητας | optional | cardinality: unlimited | references taxonomy_term → tags — Εισάγετε μία λίστα λέξεων διαχωρισμένων με κόμματα. Για παράδειγμα: Άμστερνταμ, Μεξικό, "Κλίβελαντ, Οχάιο"
  - Κυρίως κείμενο (`body`): Κείμενο (μορφοποιημένο, μεγάλο, με περίληψη) | optional | cardinality: 1
  - Σχόλια (`comment`): Σχόλια | optional | cardinality: 1

### Εισερχόμενα (`incoming`)
- Purpose: n/a
- Fields:
  - Ref ID (`field_ref_id`): Κείμενο (απλό) | optional | cardinality: 1
  - SA number (`field_sa_number`): Κείμενο (απλό) | optional | cardinality: 1
  - Sani user (`field_sani_user`): Κείμενο (απλό) | optional | cardinality: 1
  - TAA project (`field_taa_project`): Κείμενο (απλό) | optional | cardinality: 1
  - Thematic unit (`field_thematic_unit`): Κείμενο (απλό) | optional | cardinality: 1
  - Transparency Requirement (`field_transparency_requirement`): Κείμενο (απλό) | optional | cardinality: 1
  - Working days (`field_working_days`): Αριθμός (ακέραιος) | optional | cardinality: 1
  - Αιτούμενη προθεσμία (`field_requested_deadline`): Ημερομηνία | optional | cardinality: 1
  - Αποστολέας (`field_sender`): Κείμενο (απλό) | optional | cardinality: 1
  - Αρχεία (`field_documents`): Entity reference revisions | optional | cardinality: unlimited | references paragraph → documents
  - Βασικός χειριστής (`field_basic_operator`): Αναφορά οντότητας | optional | cardinality: 1 | references user
  - Είδος Εισερχομένου (`field_incoming_type`): Αναφορά οντότητας | optional | cardinality: 1 | references taxonomy_term → incoming_type
  - Ενδιάμεση προθεσμία (`field_interim_deadline`): Ημερομηνία | optional | cardinality: 1
  - Ημερομηνία εισόδου (`field_entry_date`): Ημερομηνία | optional | cardinality: 1
  - Ημερομηνία πληρότητας (`field_completion_date`): Ημερομηνία | optional | cardinality: 1
  - Θέμα (`field_subject`): Text (formatted, long) | optional | cardinality: 1
  - Λοιπές Σημειώσεις (`field_notes`): Κείμενο (απλό, μακρύ) | optional | cardinality: 1
  - Νόμιμη προθεσμία (`field_legal_deadline`): Ημερομηνία | optional | cardinality: 1
  - Παρατηρήσεις (`field_remarks`): Entity reference revisions | optional | cardinality: unlimited | references paragraph → remark
  - Προτεραιότητα (`field_priority`): Αναφορά οντότητας | optional | cardinality: 1 | references taxonomy_term → priority
  - Προϊστάμενος (`field_supervisor`): Αναφορά οντότητας | optional | cardinality: 1 | references user
  - Πρωτόκολλο  εισερχόμενο (`field_protocol_incoming`): Κείμενο (απλό) | optional | cardinality: 1
  - Σύνδεση με άλλα εισερχόμενα (`field_related_incoming`): Αναφορά οντότητας | optional | cardinality: unlimited | references node → incoming
  - Υπόθεση (`field_case`): Αναφορά οντότητας | optional | cardinality: unlimited | references taxonomy_term → case — Προαιρετική αναφορά σε μία ή περισσότερες σχετικές υπόθεσεις
  - Φορέας Ευθύνης (`field_responsible_entity`): Αναφορά οντότητας | optional | cardinality: 1 | references taxonomy_term → responsible_entity
  - Χειριστές (`field_operators`): Αναφορά οντότητας | optional | cardinality: unlimited | references user

### Βασική σελίδα (`page`)
- Purpose: Χρησιμοποιήστε τις <em>βασικές σελίδες</em> για το στατικό σας περιεχόμενο, όπως μια σελίδα 'Σχετικά'.
- Fields:
  - Κυρίως κείμενο (`body`): Κείμενο (μορφοποιημένο, μεγάλο, με περίληψη) | optional | cardinality: 1

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

## User profile
- Fields:
  - Όνομα (`field_first_name`): Κείμενο (απλό) | optional | cardinality: 1
  - Αδειες (`field_absences`): Date range | optional | cardinality: unlimited
  - Εικόνα (`user_picture`): Εικόνα | optional | cardinality: 1 — Το εικονικό σας πρόσωπο ή εικόνα.
  - Επώνυμο (`field_last_name`): Κείμενο (απλό) | optional | cardinality: 1


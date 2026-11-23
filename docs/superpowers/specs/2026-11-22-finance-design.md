# Οικονομικά (cash book, documents, payables, scanning) — design

Status: design approved by the user 2026-11-22; spec awaiting review. Target release v3.510.0.

## Why

A volunteer group has money coming in (dues, donations, sponsorships) and going out (fuel, vehicle service, equipment, rent), and today none of it lives in VolunteerOps except the annual dues. The treasurer keeps receipts in a drawer and totals in a spreadsheet. This adds a **Οικονομικά** section: a cash book with accounts and balances, every income/expense document scanned or uploaded and read by the AI, VAT worked out automatically, and a list of unpaid invoices with reminders.

## Out of scope (explicitly)

- **Issuing** documents (receipts, invoices) and anything **myDATA / ΑΑΔΕ**. The app only records documents the group received or holds.
- Volunteer-submitted expenses / reimbursements. Only users with `finance_manage` touch finance.
- Per-branch cash books, recurring expenses, budgets, bank-statement reconciliation. Later, if asked.

## Model: document and money movement are separate

- A **document** (`finance_documents`) is the paper: who, ΑΦΜ, number, date, due date, VAT lines, total, category, file. Direction `IN` (income: donation receipt, sponsorship, deposit slip) or `OUT` (expense: invoice, receipt).
- A **movement** (`finance_movements`) is money actually entering or leaving an **account** on a date. It may point at a document (a payment of it) or stand alone (bank fee, cash deposit). A transfer between two accounts is a pair of movements sharing `transfer_group`.
- A document's **paid amount** is the sum of its linked movements. `paid >= total` → paid; `0 < paid < total` → partially paid; `0` → open. Partial payments are allowed; one movement pays at most one document.
- **Account balance** = `opening_balance` + sum of its movements (IN positive, OUT negative). Never stored, always computed.
- A document can be saved **already paid** in one step (the common cash-receipt case): the form has «Πληρώθηκε τώρα από [λογαριασμός]», which creates the movement in the same transaction.

### Schema (migration 227, plus `sql/schema.sql`)

- `finance_accounts`: `id`, `name`, `kind ENUM('CASH','BANK','CARD','OTHER')`, `iban NULL`, `opening_balance DECIMAL(12,2) DEFAULT 0`, `opening_date DATE`, `is_active`, `sort_order`, timestamps. Seeded: «Ταμείο μετρητών» (CASH).
- `finance_categories`: `id`, `name`, `direction ENUM('IN','OUT')`, `is_active`, `sort_order`. Seeded OUT: Καύσιμα, Συντήρηση οχημάτων, Ασφάλειες οχημάτων, Εξοπλισμός, Αναλώσιμα / Φαρμακείο, Ενοίκιο, Λογαριασμοί κοινής ωφέλειας, Εκπαίδευση, Λοιπά έξοδα. Seeded IN: Συνδρομές μελών, Δωρεές, Χορηγίες, Επιχορηγήσεις, Λοιπά έσοδα.
- `finance_documents`: `id`, `direction`, `doc_type ENUM('INVOICE','RECEIPT','DONATION','DEPOSIT','OTHER')`, `counterparty_name`, `counterparty_vat NULL` (ΑΦΜ, 9 digits when present), `doc_number NULL`, `doc_date DATE`, `due_date DATE NULL`, `net_total`, `vat_total`, `gross_total DECIMAL(12,2)`, `category_id`, `mission_id NULL`, `vehicle_id NULL`, `notes`, `file_original_name`, `file_stored_name`, `file_mime`, `scan_source ENUM('MANUAL','AI')`, `scan_provider NULL`, `created_by`, `updated_by`, timestamps. Index on `(direction, doc_date)`, `(due_date)`.
- `finance_document_vat`: `id`, `document_id` (FK cascade), `rate DECIMAL(5,2)` (24, 13, 6, 17, 9, 4, 0), `net DECIMAL(12,2)`, `vat DECIMAL(12,2)`. One row per rate on the document.
- `finance_movements`: `id`, `account_id`, `direction ENUM('IN','OUT')`, `amount DECIMAL(12,2) > 0`, `movement_date DATE`, `document_id NULL` (FK set null), `category_id NULL` (used when no document), `transfer_group CHAR(16) NULL`, `subscription_id NULL` (the dues payment it came from), `description`, `created_by`, timestamps. A movement's direction must equal its document's direction (validated in PHP).
- Money is `DECIMAL(12,2)` everywhere and computed in PHP with integer cents, never floats.

## VAT, automatically

- Rates offered: 24, 13, 6 (mainland) and 17, 9, 4 (reduced-rate islands), plus 0 / «Απαλλαγή». Default rate is a setting (`finance_default_vat_rate`, 24).
- On the form, per VAT line, the user fills **either** the gross **or** the net; the other two are computed: `net = round(gross / (1 + r/100), 2)`, `vat = gross - net` (or `vat = round(net * r/100, 2)`, `gross = net + vat`).
- Document totals are the sums of the lines. If the user types a total that differs from the lines by more than €0.02, the form shows a warning and saving requires confirming it (real receipts round per line).
- A plain receipt with no VAT breakdown is one line with the chosen rate.
- Pure functions in `includes/finance-functions.php` (`financeSplitGross()`, `financeSplitNet()`, `financeDocumentTotals()`), unit-tested.

## Scanning

Page **Παραστατικά → Νέο**: «Φωτογραφία» (opens the phone camera via `capture="environment"`) or «Αρχείο» (JPG/PNG/WebP/PDF, max 10 MB; no HEIC: only Safari can decode it for the scan, and iPhones hand the file picker a JPEG anyway).

1. **In the browser:** images are downscaled to max 2000 px long edge, JPEG ~0.85. A PDF's first page is rendered to an image with pdf.js (cdnjs, pinned version). The original file is what gets stored; the downscaled image is what goes to the AI.
2. **Server** (`finance-scan.php`, POST, CSRF, `finance_manage`): sends the image to the AI and returns JSON. Nothing is saved at this step.
3. **The form fills** from the JSON: direction guess, doc type, counterparty, ΑΦΜ, number, date, VAT lines, total, suggested category (matched by name against existing categories). Fields the AI was unsure of are highlighted. The treasurer reviews and presses Αποθήκευση; only then are the file and document stored.

### Which AI: the free ones first

The scanner uses the providers and keys already in **Ρυθμίσεις → AI**, but **ignores the admin's "primary provider" choice** and walks them in cost order, so a free tier is always tried before anything metered:

- Order is `aiFailoverOrder()` (failover_rank): Gemini and Groq (free tiers, rank 10) → xAI Grok (metered, 50) → DeepSeek (90).
- Only providers **that have a key** and **can read images** are in the chain. `aiProviders()` gains a `vision_model` per provider: Gemini → its configured model (Flash models read images), Groq → a Llama 4 vision model, Grok → a vision-capable Grok model, DeepSeek → none (skipped). Like model names today, these are defaults that can be overridden in Settings (`ai_vision_model_<provider>`).
- A new `includes/ai-vision.php` holds `aiVisionChain()` (that list) and `aiVisionChat()`, which walks it with the existing single-attempt `aiChatOnce()` (same retryable/failover rules as `aiChat()`, which itself is left untouched) and messages whose content is an OpenAI-style `[{type:'text'}, {type:'image_url', image_url:{url:'data:image/jpeg;base64,...'}}]` array.
- The prompt asks for strict JSON with a fixed schema and `null` for anything not legible; the reply is parsed with `aiDecodeJsonLoose()` and then **validated in PHP** (dates real, amounts numeric, ΑΦΜ 9 digits with the check digit, VAT lines summing to the total). Invalid fields are dropped, not trusted.
- No key at all, or no vision-capable provider keyed: the scan button is hidden and the page says why; upload + manual entry work exactly the same.
- **Privacy notice** on the scan page: «Η εικόνα αποστέλλεται στον πάροχο AI (π.χ. Google) για ανάγνωση.» The provider label actually used is stored in `scan_provider`.

## Payables (Υποχρεώσεις)

- **Υποχρεώσεις** lists OUT documents with `paid < total`, sorted by due date: overdue (red), due in ≤ 7 days (amber), later, no due date. Shows remaining amount and a «Πληρωμή» button (account, date, amount defaulting to the remainder).
- The overview shows «Οφειλές» total and the count overdue.
- **Reminders:** new `cron_finance_due.php`, included from `cron_daily.php`. Once per document per stage (7 days before, on the due date, overdue), it calls `sendNotification()` to every active user with `finance_manage`. Stages tracked in `finance_documents.reminder_stage TINYINT`.

## Pages and menu

New sidebar section **Οικονομικά** in `includes/header.php`, after Επιχειρησιακό. The section shows when the user has `finance_manage` **or** `subscriptions_manage`; each item keeps its own permission (Συνδρομές → `subscriptions_manage`, everything else → `finance_manage`), so a dues clerk keeps exactly the access they have today.

| Page | File | What |
|---|---|---|
| Επισκόπηση | `finance.php` | balances per account, this month in/out, οφειλές, documents due soon, last 10 movements |
| Κινήσεις | `finance-movements.php` | all movements, filters (period, account, direction, category), new movement / transfer |
| Παραστατικά | `finance-documents.php`, `finance-document-form.php` | list with filters and paid status; create (scan/upload) and edit |
| Υποχρεώσεις | `finance-payables.php` | as above |
| Συνδρομές | `subscriptions.php` (existing) | **moved** here from the Διοίκηση section; the page itself is unchanged apart from the cash-book hook below |
| Αναφορές | `finance-reports.php` | period summary by category, by account, VAT summary per rate (net / VAT / gross, IN and OUT separately), CSV export and print view for the accountant |
| Ρυθμίσεις | `finance-settings.php` | accounts, categories, default VAT rate, dues payment method → account, import of past dues |

File access: `finance-file.php?id=` streams the stored file after a `finance_manage` check; files live in `uploads/finance/` protected by `ensurePrivateUploadDir()`, stored names random, like the subscription receipts.

Permission: new `finance_manage` («Οικονομικά», icon `bi-wallet2`) in `includes/permissions.php`, in a new «Οικονομικά» group. System admins have it implicitly (as every permission); custom roles get it from the role editor.

## Hooks into what exists

- **Dues (Συνδρομές) are part of the cash book.** The existing dues feature (`subscriptions.php`, `volunteer_subscriptions`, IRIS requests, receipts, expiry reminders) stays as it is and becomes the Οικονομικά source of «Συνδρομές μελών» income:
  - **Record payment** with an amount → an IN movement is created in the same DB transaction: category «Συνδρομές μελών», `subscription_id` set, date = payment date, description «Συνδρομή — <όνομα εθελοντή>».
  - **Which account** comes from the payment method already on the dues form, mapped in Ρυθμίσεις: Μετρητά → (default «Ταμείο μετρητών»), Τραπεζική κατάθεση → a BANK account, Κάρτα → a CARD account, Άλλο / κενό → the cash account. Settings `finance_dues_account_<method>`; an unmapped method falls back to the cash account, so a dues payment is never silently left out of the books.
  - **Edit** of a dues payment updates its movement (amount, date, account); amount cleared → movement removed. **Delete** removes the movement (explicitly, from the delete action: `volunteer_subscriptions` is not in `sql/schema.sql`, so no FK is declared on `subscription_id`).
  - No amount → no movement (nothing to book).
  - The movement row in Κινήσεις links to the dues payment and its receipt (`subscription-receipt.php`); dues movements are edited only from Συνδρομές, never from Κινήσεις, so the two never disagree.
  - **Past dues:** the migration does not touch history. Ρυθμίσεις has «Εισαγωγή παλαιών συνδρομών από [ημερομηνία]», which creates the missing movements for dues payments on or after that date (idempotent: skips any with a movement already). The treasurer picks the same date as the accounts' opening date, so opening balance + imported dues don't double-count.
  - PHPUnit: method→account mapping and fallback, create/edit/delete keeps exactly one movement per paid dues row, import is idempotent.
- **Mission / vehicle:** optional selects on the document form; `vehicle-view.php` and `mission-view.php` are not changed in this release.
- **Audit:** every create/update/delete of documents, movements, accounts, categories goes through `logAudit()`.

## Deleting and editing

- Documents and movements can be edited and deleted by `finance_manage`, always audited. Deleting a document deletes its file and unlinks (does not delete) its movements, which then show «χωρίς παραστατικό» so the balance never silently changes.
- An account or category with movements/documents cannot be deleted, only deactivated.

## Testing

- PHPUnit: VAT split functions (rounding cases, multi-rate), document paid status, account balance, ΑΦΜ check-digit validation, scan-JSON validator (garbage, partial, totals mismatch), `aiVisionChain()` ordering (free first, unkeyed and non-vision providers excluded, primary ignored).
- Manual in the browser: phone camera scan of a real Greek receipt and a multi-rate invoice, PDF upload, partial payment, transfer, overdue reminder via a forced cron run, CSV export.

## Release

v3.510.0, one migration (227), CHANGELOG entry, `sql/schema.sql` updated, user manual section «Οικονομικά».

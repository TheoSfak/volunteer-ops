# Οικονομικά — browser checklist (v3.510.0)

Run on the local XAMPP site with AI enabled and a Gemini key, as a system admin, then once as a custom-role user with only «Ετήσιες Συνδρομές».

1. Menu: «Οικονομικά» section after «Επιχειρησιακό»; «Ετήσιες Συνδρομές» is in it and no longer under «Διοίκηση». The dues-only user sees only «Ετήσιες Συνδρομές» there; opening finance.php redirects them.
2. Ρυθμίσεις Οικονομικών: add «Τράπεζα» (BANK) with an opening balance; map «Τραπεζική κατάθεση / IRIS» to it. An opening balance such as «1.500,00 €» or «abc» is refused; `1500.00` is accepted. A duplicate category name is refused.
3. Νέο έξοδο → «Φωτογραφία» on a phone: the camera opens (a real phone-camera scan, not a desktop file). Scan a real fuel receipt: fields fill, the provider name shows, uncertain fields are outlined. The privacy notice «Η εικόνα αποστέλλεται στον πάροχο AI (π.χ. Google) για ανάγνωση.» is visible. Save with «Πληρώθηκε ήδη» from the cash account. The document shows «Εξοφλημένο»; the cash balance drops.
4. Scan a multi-rate supermarket invoice (24% + 13%): two VAT lines; totals match the printed total.
5. Upload a PDF invoice (a real PDF scan): the first page is rendered in the browser and read. Uploading a non-image renamed to .jpg is refused (only real JPEG/PNG/WebP). Turn «Πληρώθηκε ήδη» off and set a due date 3 days ahead: it appears in Υποχρεώσεις in amber.
6. Partial payment from the document's «Πληρωμές» box: status «Μερικώς», remaining correct; paying more than the remainder is refused. On a paid document, lowering the total below the amount paid is refused.
7. Type a printed total 0,50 € off the lines: the warning appears and saving needs the checkbox.
8. ΑΦΜ: «EL 123456789» style input (EL prefix, spaces) is accepted when the check digit is valid; a wrong check digit is flagged in the browser and refused by the server.
9. Edit a document whose category has since been deactivated, and one linked to an old (closed) mission: the category / mission link is kept after saving.
10. Κινήσεις: a bank fee without a document; a transfer cash → bank; deleting the transfer removes both halves. With more than 500 rows the notice about the 500-row limit appears.
11. Ετήσιες Συνδρομές: record a 30 € payment by «Τραπεζική κατάθεση»: an IN movement «Συνδρομή — <name>» appears in the bank account. Edit it to 35 €: the movement follows. Delete it: the movement goes. The movement has no delete button in Κινήσεις (dues movements can only be removed via Συνδρομές).
12. Settings → past-dues import from 1/1 of this year: the count matches; running it again books 0.
13. Αναφορές: totals per category, per account, VAT per rate; CSV opens in Excel with Greek text intact and `;` as separator (columns split correctly); the print view hides the sidebar and prints cleanly.
14. With no AI key at all: the scan button is hidden and the page says why; manual entry still works.
15. Settings → AI → connection test for the vision models: Groq (`meta-llama/llama-4-scout-17b-16e-instruct`) and Grok (`grok-4-fast-non-reasoning`). These are unverified live — confirm each answers an image request, and that a scan falls through to the next provider when one fails. DeepSeek is never used for scanning.
16. `php cron_finance_due.php` with a document due today: a notification «Τιμολόγιο λήγει σήμερα» reaches the system admins and users with the «Οικονομικά» permission; a second run sends nothing. The reminder is recorded only once delivered to at least one person.
17. Hostinger: the cron job runs `cron_daily.php` (which calls `cron_finance_due.php`) — confirm in hPanel and after a day check the notification.

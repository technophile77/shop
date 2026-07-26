# Perla's Flowers

## Weekly sales report

A repeatable report that combines web-store sales (MySQL) with DoorDash
sales (a manually downloaded CSV) into one row per week, keeping flower
sales separate from delivery fees, sales tax, and DoorDash commission.

```bash
php bin/sales-report.php --doordash=doordash-export.csv
```

Output is written **outside the webroot**, to `~/private/flowers-sales/`
(never under `public_html`, since this project's `.htaccess` serves any file
that exists on disk directly). Run with no `--doordash` flag and the report
still works, but covers the web store only — it must not be treated as a
complete picture of sales until a DoorDash export has been imported.

The DoorDash export itself comes from the Merchant Portal's **Reports →
Create report → Financial → Transaction details** flow, exported as CSV.

See [`docs/weekly-sales-report.md`](docs/weekly-sales-report.md) for the full
runbook: all CLI flags, the accounting rules, the DoorDash export
walkthrough, and known limitations.

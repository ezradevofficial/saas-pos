---
id: accounting-read-tax-return
title: Read the tax return summary
module: accounting
order: 70
permissions: [accounting.report.view, accounting.period.view]
roles: [accountant]
screens: [/accounting/tax-returns]
---
"Tax returns" is a summary to prepare your return. It is not the filed form. Copy its figures into the official form of the tax authority yourself; the form's boxes are not filled in for you. In Kenya the page is the "VAT return summary", in the DR Congo the "TVA declaration summary".

1. Open "Tax returns" under "Taxes". If you have more than one company, choose it in "Company".
2. Choose the "Period". It lists your accounting periods; "Custom dates" lets you set a start and an end. The start must not be after the end.
3. Read "Output tax" (collected on sales) and "Input tax" (paid on purchases). Each row is one tax code and rate, with "Taxable value", "Tax" and "Lines".
4. Tap a row to see the documents and journals behind it. Tap "Close" when done.
5. Read "Net payable" (or "Net recoverable" when input tax is higher) and whether the total "Matches the ledger". If it says "Does not match the ledger", contact support before you file.
6. Where the country requires it, a line shows how many invoices and credit notes the tax authority accepted (eTIMS in Kenya, the DGI in the DR Congo).
7. Tap "Export CSV" to keep the figures or work on them in a spreadsheet.

What the marks mean:

- A row marked * has no taxable value. Sales posted by other modules do not keep the value per tax code in their journals, so only the tax is shown. Do not add the taxable value from this row; take it from your sales reports.
- "No tax code" collects tax lines with no code, for example manual journals or journals posted before codes were kept. Check these journals if the amount is not small.
- "Rate needed" means a tax code has no confirmed rate. Confirm it in the tax settings, then reopen the page.

<?php

namespace App\Core\DocumentTemplates;

/**
 * TPL-01: where a document type's merge fields come from. A module
 * registers one per document type it prints (DataSources::register).
 *
 * Data is one plain array, the same shape for every type, which the PHP
 * and the till's JS renderer both read:
 *
 *   currencies: {KES: 2, CDF: 0}            decimals per currency used
 *   company:  {name, legal_name, tax_id, address, phone, email, country, logo, custom}
 *   branch:   {name, code, address}         location: {name, code}
 *   document: {number, date, cashier, currency, reference, reason, ...}
 *   customer: {name, tax_id, phone, email, address, tags, custom} | null
 *   lines:    [{column => value}]           totals: {subtotal, discount, tax, total, dual, tax_lines: [{name, rate, tax}]}
 *   payments: [{method, amount, reference}] change: money | null
 *   fiscal:   {authority, status, invoice_number, receipt_number, receipt_signature,
 *              internal_data, control_unit_id, authority_time, qr} | null
 *
 * Money is `{minor: "112500", currency: "KES"}` (minor units as a digit
 * string); quantities and rates are decimal strings without trailing
 * zeros; dates are already in the place's time zone ("2026-10-09 14:32").
 * Business record names (items, customers, the company) are as entered.
 */
interface DataSource
{
    /** The document type it feeds (DocumentTypes::TYPES). */
    public function type(): string;

    /**
     * The document's own fields beyond the standard groups, as
     * `path => type` (text, money, date, qty, rate), e.g.
     * `['document.cashier' => 'text']`.
     *
     * @return array<string, string>
     */
    public function documentFields(): array;

    /**
     * The columns a lines table may show, `column => type`.
     *
     * @return array<string, string>
     */
    public function lineColumns(): array;

    /** Sample data in the data shape, for the designer's preview. */
    public function sample(?string $country): array;

    /** Whether real records exist (false: sample only, until its module comes). */
    public function live(): bool;
}

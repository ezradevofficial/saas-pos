<?php

namespace App\Core\Automation\Capabilities;

/**
 * AUTO-01 date triggers ("30 days before the contract ends"): a document
 * type that can list its documents whose date field falls on a given day.
 * Called once a day per company by the date scan, in the tenant's context.
 */
interface FindsDocumentsByDate
{
    /**
     * Ids of the company's documents (not archived) whose $field falls on
     * $date ('Y-m-d'); a date-time field is read on its day in $timezone
     * (the company's).
     *
     * @return list<string>
     */
    public function documentsOnDate(string $field, string $date, string $companyId, string $timezone): array;
}

<?php

namespace App\Core\DocumentTemplates;

/**
 * A data source with real records (TPL-04): loads one record, under the
 * current tenant's row-level security, as DocumentData. Used by the
 * output services (PDF, email, shared links) that only know the document
 * type and the record id.
 */
interface RecordSource extends DataSource
{
    public function load(string $recordId): ?DocumentData;
}

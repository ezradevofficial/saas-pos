<?php

namespace App\Core\Fiscal\Contracts;

use Carbon\CarbonImmutable;

/**
 * A FiscalDocumentSource that can list a company's earlier documents, for
 * the back office's "Send earlier sales" (documents made before the
 * company switched transmission on). Read in the current tenant.
 */
interface ListsFiscalDocuments
{
    /**
     * The company's documents issued at or after $from, oldest first, a sale
     * before its refunds and void.
     *
     * @return iterable<array{0: string, 1: string}> [document type, document id]
     */
    public function documentsSince(string $companyId, CarbonImmutable $from): iterable;
}

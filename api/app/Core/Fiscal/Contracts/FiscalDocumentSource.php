<?php

namespace App\Core\Fiscal\Contracts;

use App\Core\Fiscal\FiscalDocument;

/**
 * Implemented by a module whose documents go to the tax authority (the POS
 * for sales, refunds and voids) and registered with FiscalSources in the
 * module's provider. The core asks it for a document by type and id, in
 * the document's tenant, and never reads the module's tables itself
 * (architecture rule 1).
 */
interface FiscalDocumentSource
{
    /** The source key stored on submissions (`pos`). */
    public function key(): string;

    /** The document as the authority needs it (FiscalDocument's shape); throws when it does not exist. */
    public function document(string $documentType, string $documentId): FiscalDocument;
}

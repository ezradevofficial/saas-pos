<?php

namespace Modules\Hotel\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** HOT-27, HOT-34: a folio was settled into its invoice (after commit). */
class FolioSettled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $folioId,
        public readonly ?string $invoiceNumber,
    ) {}
}

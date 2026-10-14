<?php

namespace Modules\Procurement\Documents;

use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use DateTimeInterface;
use Modules\Procurement\ProcurementServiceProvider;

/**
 * PRC-19, NUM-01: the numbers of Procurement's documents, from the format
 * in force at the document's branch (else company, else tenant; the
 * defaults below, reset yearly), drawn inside the document's own
 * transaction when it is submitted or posted (drafts have none).
 */
class DocumentNumbers
{
    public const REQUISITION = 'procurement.requisition';

    public const RFQ = 'procurement.rfq';

    public const TENDER = 'procurement.tender';

    public const PO = 'procurement.po';

    public const RECEIPT = 'procurement.receipt';

    public const INVOICE = 'procurement.invoice';

    public const LANDED_COST = 'procurement.landed_cost';

    public const TYPES = [
        self::REQUISITION => 'PR-{BRANCH}-{YYYY}-{00001}',
        self::RFQ => 'RFQ-{BRANCH}-{YYYY}-{00001}',
        self::TENDER => 'TND-{BRANCH}-{YYYY}-{00001}',
        self::PO => 'PO-{BRANCH}-{YYYY}-{00001}',
        self::RECEIPT => 'GRN-{BRANCH}-{YYYY}-{00001}',
        self::INVOICE => 'PINV-{BRANCH}-{YYYY}-{00001}',
        self::LANDED_COST => 'LC-{BRANCH}-{YYYY}-{00001}',
    ];

    public function __construct(private readonly Numbering $numbering) {}

    /** @return list<DocumentNumberType> */
    public static function types(): array
    {
        return array_map(
            // POs and GRNs know their (delivery) location; the others only their branch.
            fn (string $key, string $pattern) => new DocumentNumberType($key, ProcurementServiceProvider::MODULE, $pattern, NumberFormat::RESET_YEARLY,
                in_array($key, [self::PO, self::RECEIPT], true) ? ['BRANCH', 'LOCATION'] : ['BRANCH'], langKey: 'procurement.numbering.'.substr($key, strlen('procurement.'))),
            array_keys(self::TYPES),
            self::TYPES,
        );
    }

    public function next(string $type, string $companyId, string $branchId, ?string $locationId = null, ?DateTimeInterface $at = null): string
    {
        return $this->numbering->next($type, new NumberContext(
            Company::query()->findOrFail($companyId),
            Branch::query()->findOrFail($branchId),
            $locationId === null ? null : Location::query()->findOrFail($locationId),
            at: $at,
        ))->number;
    }
}

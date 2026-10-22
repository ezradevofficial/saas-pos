<?php

namespace App\Core\Billing\Events;

use App\Core\Billing\Models\OperatorInvoice;

/**
 * An operator invoice was just marked paid (ADR 016), raised in the
 * operator's context inside the transaction that paid it, so listeners
 * (MKT-03: the developers' payable ledger) commit or roll back with it.
 * Raised once per invoice: the invoice row is locked and was still open.
 */
final class OperatorInvoicePaid
{
    public function __construct(public readonly OperatorInvoice $invoice) {}
}

<?php

namespace App\Core\Fiscal;

use InvalidArgumentException;

/**
 * A document as a module hands it to the fiscal queue (FiscalDocumentSource):
 * everything the tax authority needs, so the core never reads a module's
 * tables. Stored as the submission's payload when queued.
 *
 * Shape (amounts are integers in minor units of `currency`, quantities and
 * rates decimal strings):
 *
 *     type            sale | refund | void
 *     id              the document's UUID in its module
 *     number          its printed number (receipt number)
 *     issued_at       ISO 8601
 *     company_id, branch_id?, location_id?
 *     currency        ISO 4217
 *     original?       {type: 'sale', id}   for a refund or void
 *     customer?       {tin?, name?}
 *     payment_type    cash | card | mobile_money | credit | other
 *     cashier?        {id, name}
 *     lines           [{line_no, item_id, item_code, item_name, barcode?,
 *                       classification_code?, unit_code?, packaging_code?,
 *                       qty, unit_price_minor, discount_minor, tax_code_id?,
 *                       tax_rate?, tax_minor, total_minor}]
 *     totals          {tax_minor, total_minor}
 *
 * `total_minor` is what the customer pays for the line, tax included;
 * `tax_rate` is the rate the module applied (never filled in here).
 */
final class FiscalDocument
{
    public const PAYMENT_TYPES = ['cash', 'card', 'mobile_money', 'credit', 'other'];

    /** @param array<string, mixed> $data */
    private function __construct(public readonly array $data) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['type', 'id', 'issued_at', 'company_id', 'currency', 'payment_type', 'lines', 'totals'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new InvalidArgumentException("Fiscal document lacks [{$key}].");
            }
        }

        if (! in_array($data['type'], ['sale', 'refund', 'void'], true)) {
            throw new InvalidArgumentException('Unknown fiscal document type.');
        }

        if ($data['type'] !== 'sale' && ! isset($data['original']['id'])) {
            throw new InvalidArgumentException('A refund or void names its original sale.');
        }

        if (! in_array($data['payment_type'], self::PAYMENT_TYPES, true)) {
            $data['payment_type'] = 'other';
        }

        if (! is_array($data['lines']) || $data['lines'] === []) {
            throw new InvalidArgumentException('A fiscal document has lines.');
        }

        foreach ($data['lines'] as $line) {
            foreach (['line_no', 'item_name', 'qty', 'tax_minor', 'total_minor'] as $key) {
                if (! array_key_exists($key, $line)) {
                    throw new InvalidArgumentException("Fiscal document line lacks [{$key}].");
                }
            }
        }

        return new self($data);
    }

    public function type(): string
    {
        return $this->data['type'];
    }

    public function toArray(): array
    {
        return $this->data;
    }
}

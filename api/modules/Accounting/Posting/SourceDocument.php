<?php

namespace Modules\Accounting\Posting;

use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * ACC-07: a source document as another module reports it to the ledger
 * (docs/modules/accounting.md, "the source contract"): where it posts, its
 * identity (the idempotency key with the company), its date, its amounts
 * by field in one transaction currency, the rate it used and its
 * dimensions. Serialisable, so a refused source is kept and retried.
 */
final class SourceDocument
{
    public const DIMENSIONS = ['branch_id', 'cost_centre_id', 'department_id', 'project_id', 'party_id'];

    /**
     * @param  array<string, string|int>  $amounts  field => signed minor units
     * @param  array<string, ?string>  $dimensions
     */
    public function __construct(
        public readonly string $companyId,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly CarbonImmutable $date,
        public readonly string $currency,
        public readonly array $amounts,
        public readonly ?FxSnapshot $fx = null,
        public readonly array $dimensions = [],
        public readonly ?string $reference = null,
        public readonly ?string $memo = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_.]*\z/', $sourceType) !== 1 || strlen($sourceType) > 60) {
            throw new InvalidArgumentException("Bad source type [{$sourceType}].");
        }

        foreach ($amounts as $field => $minor) {
            if (! is_string($field) || preg_match('/^-?\d+\z/', (string) $minor) !== 1) {
                throw new InvalidArgumentException("Amount [{$field}] must be whole minor units.");
            }
        }

        foreach (array_keys($dimensions) as $key) {
            if (! in_array($key, self::DIMENSIONS, true)) {
                throw new InvalidArgumentException("Unknown dimension [{$key}].");
            }
        }
    }

    /** The amount of $field (zero when the source has none). */
    public function amount(string $field): Money
    {
        return Money::ofMinor((string) ($this->amounts[$field] ?? '0'), $this->currency);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'company_id' => $this->companyId,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'date' => $this->date->toIso8601String(),
            'currency' => $this->currency,
            'amounts' => array_map('strval', $this->amounts),
            'fx' => $this->fx?->jsonSerialize(),
            'dimensions' => $this->dimensions,
            'reference' => $this->reference,
            'memo' => $this->memo,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $fx = $data['fx'] ?? null;

        return new self(
            companyId: $data['company_id'],
            sourceType: $data['source_type'],
            sourceId: $data['source_id'],
            date: CarbonImmutable::parse($data['date']),
            currency: $data['currency'],
            amounts: $data['amounts'] ?? [],
            fx: $fx === null ? null : new FxSnapshot($fx['rate'], $fx['base'], $fx['quote'], $fx['kind'] ?? null, isset($fx['effective_at']) ? CarbonImmutable::parse($fx['effective_at']) : null),
            dimensions: $data['dimensions'] ?? [],
            reference: $data['reference'] ?? null,
            memo: $data['memo'] ?? null,
        );
    }
}

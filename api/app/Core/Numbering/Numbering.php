<?php

namespace App\Core\Numbering;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * NUM-01: document numbers. The format for a document type is the
 * branch's, else the company's, else the tenant's; a tenant without one
 * gets the type's default on first use. Each format counts per period
 * (`all`, or the year when it resets yearly) in number_sequences.
 *
 * Counters move with one `UPDATE ... RETURNING`, which takes the row lock
 * until the caller's transaction ends: concurrent documents wait for each
 * other and never share a number.
 *
 * Gapless formats (where tax rules require it) must be drawn inside the
 * document's own transaction: if it rolls back, the counter rolls back
 * with it, so no number is lost. Other formats may be drawn anywhere.
 * NUM-02 device ranges reserve a whole block from the same counter; a
 * gapless format can never be ranged (a range's unused tail is a gap).
 */
class Numbering
{
    /** Longest number stored (receipt numbers, frozen range patterns): 80 characters. */
    public const MAX_NUMBER_LENGTH = 80;

    public function __construct(
        private readonly DocumentNumberTypes $types,
        private readonly Auditor $auditor,
    ) {}

    public function type(string $key): DocumentNumberType
    {
        return $this->types->find($key) ?? throw new InvalidArgumentException("Unknown numbered document type [{$key}].");
    }

    /** The format already in force at a place (no seeding): the branch's, the company's or the tenant's. */
    public function formatAt(string $type, ?string $companyId, ?string $branchId): ?NumberFormat
    {
        $formats = NumberFormat::query()->where('document_type', $type)->get();

        return ($branchId === null ? null : $formats->firstWhere('branch_id', $branchId))
            ?? ($companyId === null ? null : $formats->first(fn (NumberFormat $f) => $f->company_id === $companyId && $f->branch_id === null))
            ?? $formats->first(fn (NumberFormat $f) => $f->company_id === null);
    }

    /** The format in force for $type at the company (and branch), seeding the tenant default on first use. */
    public function formatFor(string $type, string $companyId, ?string $branchId = null): NumberFormat
    {
        $definition = $this->type($type);
        $formats = NumberFormat::query()->where('document_type', $type)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
            ->get();

        $found = ($branchId === null ? null : $formats->firstWhere('branch_id', $branchId))
            ?? $formats->first(fn (NumberFormat $f) => $f->company_id === $companyId && $f->branch_id === null)
            ?? $formats->first(fn (NumberFormat $f) => $f->company_id === null);

        return $found ?? $this->seedDefault($definition);
    }

    /** The next number of $type for the document described by $context. */
    public function next(string $type, NumberContext $context): IssuedNumber
    {
        $format = $this->formatFor($type, $context->company->id, $context->branch?->id);

        if ($format->gapless && $this->db()->transactionLevel() === 0) {
            throw new LogicException('A gapless number must be drawn inside the document\'s transaction.');
        }

        $period = $this->period($format, $context);
        [, $value] = $this->advance($format, $period, 1);

        return new IssuedNumber($value, $period, $this->render($format->parsed(), $context->values(), $value), $format->id);
    }

    /**
     * NUM-02: reserve $size consecutive values of $type's counter for the
     * place in $context (a device), with the pattern frozen for them.
     */
    public function reserve(string $type, NumberContext $context, int $size): ReservedBlock
    {
        if ($size < 1) {
            throw new InvalidArgumentException('A block holds at least one number.');
        }

        $format = $this->formatFor($type, $context->company->id, $context->branch?->id);

        if ($format->gapless) {
            throw new ApiException(422, 'numbering_gapless_range', __('core.numbering.errors.gapless_range'));
        }

        $period = $this->period($format, $context);
        $frozen = $context->placeValues();

        if ($format->reset === NumberFormat::RESET_YEARLY) {
            $frozen += ['YYYY' => $period, 'YY' => substr($period, 2)];
        }

        $pattern = $format->parsed();

        foreach ($pattern->tokens as $token) {
            if (in_array($token, Pattern::PLACE_TOKENS, true) && ! isset($frozen[$token])) {
                throw new ApiException(422, 'numbering_token_unavailable', __('core.numbering.errors.token_unavailable', ['token' => $token]));
            }
        }

        $frozenPattern = $pattern->with($frozen);

        // M5: codes changed since the format was saved could make numbers too long: refuse, never fail.
        $longest = $frozenPattern->render(['YYYY' => '0000', 'YY' => '00', 'MM' => '00'], (int) str_repeat('9', max($pattern->width, 12)));

        if (mb_strlen($frozenPattern->pattern) > self::MAX_NUMBER_LENGTH || mb_strlen($longest) > self::MAX_NUMBER_LENGTH) {
            throw new ApiException(422, 'numbering_pattern_too_long', __('core.numbering.errors.pattern_too_long', ['max' => self::MAX_NUMBER_LENGTH]));
        }

        [$sequenceId, $from] = $this->advance($format, $period, $size);

        return new ReservedBlock($format->id, $sequenceId, $period, $from, $from + $size - 1, $frozenPattern->pattern);
    }

    /**
     * A number from a frozen (ranged) pattern: its remaining date tokens
     * from $context's local date.
     */
    public static function renderFrozen(string $pattern, NumberContext $context, int $value): string
    {
        return Pattern::parse($pattern)->render($context->dateValues(), $value);
    }

    public function period(NumberFormat $format, NumberContext $context): string
    {
        return $format->reset === NumberFormat::RESET_YEARLY ? $context->local()->format('Y') : NumberSequence::ALL;
    }

    /** @param array<string, string> $values */
    private function render(Pattern $pattern, array $values, int $value): string
    {
        foreach ($pattern->tokens as $token) {
            if (! isset($values[$token])) {
                throw new ApiException(422, 'numbering_token_unavailable', __('core.numbering.errors.token_unavailable', ['token' => $token]));
            }
        }

        return $pattern->render($values, $value);
    }

    /**
     * Move the format's counter for $period by $count; returns the
     * sequence id and the first value taken. The row stays locked until
     * the surrounding transaction ends (or this statement, outside one).
     *
     * @return array{0: string, 1: int}
     */
    private function advance(NumberFormat $format, string $period, int $count): array
    {
        $db = $this->db();
        $db->insert(
            'insert into number_sequences (id, number_format_id, period, next_value, created_at, updated_at) values (?, ?, ?, 1, now(), now()) on conflict (number_format_id, period) do nothing',
            [(string) Str::uuid7(), $format->id, $period],
        );
        $row = $db->selectOne(
            'update number_sequences set next_value = next_value + ?, updated_at = now() where number_format_id = ? and period = ? returning id, next_value - ? as first',
            [$count, $format->id, $period, $count],
        );

        return [(string) $row->id, (int) $row->first];
    }

    /** The tenant-wide format from the type's default, created once (race-safe), audited when this call created it. */
    private function seedDefault(DocumentNumberType $type): NumberFormat
    {
        return $this->db()->transaction(function (Connection $db) use ($type) {
            $id = (string) Str::uuid7();
            $created = $db->affectingStatement(
                'insert into number_formats (id, document_type, pattern, reset, gapless, created_at, updated_at) values (?, ?, ?, ?, false, now(), now()) on conflict do nothing',
                [$id, $type->key, $type->defaultPattern, $type->defaultReset],
            );
            $format = NumberFormat::query()->where('document_type', $type->key)->whereNull('company_id')->firstOrFail();

            if ($created === 1) {
                $this->auditor->record('core.number_format.create', $format, null, $format->only(['document_type', 'pattern', 'reset', 'gapless']));
            }

            return $format;
        });
    }

    private function db(): Connection
    {
        return DB::connection(TenantContext::CONNECTION);
    }
}

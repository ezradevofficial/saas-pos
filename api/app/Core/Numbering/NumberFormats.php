<?php

namespace App\Core\Numbering;

use App\Core\Http\ApiException;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * NUM-01: changing how a document type is numbered at the tenant, a
 * company or a branch. A pattern may use only the place tokens the type's
 * documents know; a yearly reset needs a year token (or numbers of two
 * years would clash); a ranged type is never gapless (NUM-02); and once a
 * format has issued numbers its reset cannot change (a restarted counter
 * would repeat them).
 *
 * No two places print the same number (M2):
 * - a new company or branch format continues the counter of the format
 *   that applied there before, so it never repeats numbers already
 *   printed under the inherited one;
 * - a pattern identical to another format of the same type is refused,
 *   unless both are branch formats of one company using {BRANCH} (branch
 *   codes are unique in a company);
 * - the longest number a pattern can print (the longest branch code in
 *   reach, 10-character location and device codes, a 12-digit counter)
 *   must fit the 80 characters stored (M5).
 */
class NumberFormats
{
    public function __construct(private readonly Numbering $numbering) {}

    /**
     * @param  array{document_type: string, company_id: ?string, branch_id: ?string, pattern: string, reset: string, gapless: bool}  $data
     */
    public function save(array $data): NumberFormat
    {
        $type = $this->numbering->type($data['document_type']);
        $this->validate($type, $data['pattern'], $data['reset'], (bool) $data['gapless']);

        $this->assertFits($data);

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($data) {
            $format = NumberFormat::query()
                ->where('document_type', $data['document_type'])
                ->where('company_id', $data['company_id'])
                ->where('branch_id', $data['branch_id'])
                ->lockForUpdate()
                ->first() ?? new NumberFormat([
                    'document_type' => $data['document_type'],
                    'company_id' => $data['company_id'],
                    'branch_id' => $data['branch_id'],
                ]);

            if ($format->exists && $format->reset !== $data['reset'] && $format->sequences()->exists()) {
                throw new ApiException(422, 'numbering_reset_locked', __('core.numbering.errors.reset_locked'));
            }

            $this->assertNoTwin($format, $data);
            $inherited = $format->exists ? null : $this->numbering->formatAt($data['document_type'], $data['company_id'], $data['branch_id']);

            $format->fill(['pattern' => $data['pattern'], 'reset' => $data['reset'], 'gapless' => (bool) $data['gapless']])->save();

            if ($inherited !== null) {
                // Continue where the inherited counter stands, period by period.
                foreach (NumberSequence::query()->where('number_format_id', $inherited->id)->get() as $sequence) {
                    NumberSequence::create(['number_format_id' => $format->id, 'period' => $sequence->period, 'next_value' => $sequence->next_value]);
                }
            }

            return $format;
        });
    }

    /** M2: another format of the type printing the same numbers. */
    private function assertNoTwin(NumberFormat $format, array $data): void
    {
        $twins = NumberFormat::query()
            ->where('document_type', $data['document_type'])
            ->where('pattern', $data['pattern'])
            ->when($format->exists, fn ($q) => $q->whereKeyNot($format->id))
            ->get();

        foreach ($twins as $twin) {
            $branchesOfOneCompany = $twin->branch_id !== null && $data['branch_id'] !== null && $twin->company_id === $data['company_id'];

            if (! ($branchesOfOneCompany && Pattern::parse($data['pattern'])->uses('BRANCH'))) {
                $message = __('core.numbering.errors.pattern_collision');

                throw new ApiException(422, 'numbering_pattern_collision', $message, errors: ['pattern' => [$message]]);
            }
        }
    }

    /** M5: the longest number the pattern can print fits the stored 80 characters. */
    private function assertFits(array $data): void
    {
        $pattern = Pattern::parse($data['pattern']);
        $branchCode = (int) Branch::query()
            ->when($data['branch_id'] !== null, fn ($q) => $q->whereKey($data['branch_id']))
            ->when($data['branch_id'] === null && $data['company_id'] !== null, fn ($q) => $q->where('company_id', $data['company_id']))
            ->max(DB::raw('length(code)'));
        $longest = $pattern->render([
            'BRANCH' => str_repeat('B', max($branchCode, 10)),
            'LOCATION' => str_repeat('L', 10),
            'DEVICE' => str_repeat('D', 10),
            'YYYY' => '0000', 'YY' => '00', 'MM' => '00',
        ], (int) str_repeat('9', max($pattern->width, 12)));

        if (mb_strlen($longest) > Numbering::MAX_NUMBER_LENGTH) {
            $message = __('core.numbering.errors.pattern_too_long', ['max' => Numbering::MAX_NUMBER_LENGTH]);

            throw new ApiException(422, 'numbering_pattern_too_long', $message, errors: ['pattern' => [$message]]);
        }
    }

    private function validate(DocumentNumberType $type, string $pattern, string $reset, bool $gapless): void
    {
        try {
            $parsed = Pattern::parse($pattern);
        } catch (InvalidArgumentException $e) {
            throw new ApiException(422, 'numbering_pattern_invalid', __($e->getMessage()), errors: ['pattern' => [__($e->getMessage())]]);
        }

        foreach ($parsed->tokens as $token) {
            if (in_array($token, Pattern::PLACE_TOKENS, true) && ! in_array($token, $type->placeTokens, true)) {
                $message = __('core.numbering.errors.token_unavailable', ['token' => $token]);

                throw new ApiException(422, 'numbering_token_unavailable', $message, errors: ['pattern' => [$message]]);
            }
        }

        if ($reset === NumberFormat::RESET_YEARLY && ! $parsed->hasYear()) {
            $message = __('core.numbering.errors.yearly_needs_year');

            throw new ApiException(422, 'numbering_yearly_needs_year', $message, errors: ['pattern' => [$message]]);
        }

        if ($gapless && $type->ranged) {
            throw new ApiException(422, 'numbering_gapless_range', __('core.numbering.errors.gapless_range'), errors: ['gapless' => [__('core.numbering.errors.gapless_range')]]);
        }
    }
}

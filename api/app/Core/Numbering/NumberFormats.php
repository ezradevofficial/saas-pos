<?php

namespace App\Core\Numbering;

use App\Core\Http\ApiException;
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

            $format->fill(['pattern' => $data['pattern'], 'reset' => $data['reset'], 'gapless' => (bool) $data['gapless']])->save();

            return $format;
        });
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

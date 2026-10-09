<?php

namespace Modules\POS\Payments;

use App\Core\Audit\Auditor;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\Refund;

/**
 * POS-09: adds a flag (what the server noticed, for review) to a stored
 * sale or refund after the fact, such as a payment the provider could not
 * confirm. Once per code and payment: repeated events add nothing.
 *
 * Atomic: the row is locked and rewritten in one transaction on the
 * tenant connection (a savepoint when the caller already has one), so two
 * listeners flagging the same record at once never lose a flag.
 *
 * A record already reviewed (M3) is re-opened by a new flag: reviewed_at
 * and reviewed_by are cleared and `pos.sale.review_reopened` (or the
 * refund equivalent) is audited (AUD-01), so the new problem is seen.
 */
final class RecordFlags
{
    /** @param array<string, mixed> $detail */
    public static function add(Model $record, string $code, array $detail = []): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($record, $code, $detail) {
            $locked = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $flags = (array) ($locked->flags ?? []);

            foreach ($flags as $flag) {
                if (($flag['code'] ?? null) === $code && ($flag['detail'] ?? []) == $detail) {
                    return;
                }
            }

            $flags[] = array_filter(['code' => $code, 'detail' => $detail], fn ($value) => $value !== []);
            $reviewed = $locked->getAttribute('reviewed_at') !== null;
            $before = $reviewed ? ['reviewed_at' => $locked->getRawOriginal('reviewed_at'), 'reviewed_by' => $locked->getAttribute('reviewed_by')] : null;
            $locked->forceFill(['flags' => $flags, ...($reviewed ? ['reviewed_at' => null, 'reviewed_by' => null] : [])])->saveQuietly();

            if ($reviewed) {
                $action = ($locked instanceof Refund ? 'pos.refund' : 'pos.sale').'.review_reopened';
                app(Auditor::class)->record($action, $locked, $before, ['reviewed_at' => null, 'reviewed_by' => null, 'flag' => $code]);
            }

            $record->setRawAttributes($locked->getAttributes(), true);
        });
    }
}

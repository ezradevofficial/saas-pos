<?php

namespace Modules\Procurement\Console;

use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Support\Tx;
use Modules\Procurement\Tenders\Seal;

/**
 * ADR 019: after an APP_KEY rotation (the old key kept in
 * APP_PREVIOUS_KEYS), re-encrypt every tender's sealing key and notice
 * token with the current key, tenant by tenant under each tenant's own
 * row-level security: every tenant holding a tender, suspended and closed
 * ones included (app_tenants_with_tenders, a security-definer function
 * returning ids only).
 * Bid ciphertext is untouched: it is sealed with the tender key, which does
 * not change. Once it reports no failures, the old key may leave
 * APP_PREVIOUS_KEYS.
 */
class RewrapKeys extends Command
{
    protected $signature = 'procurement:rewrap-keys';

    protected $description = 'Re-encrypt the tenders\' sealing keys and notice tokens with the current application key';

    public function handle(TenantContext $tenants): int
    {
        $done = 0;
        $failed = 0;

        // Every tenant with a tender, suspended and closed ones too (ids only, app_tenants_with_tenders).
        $tenantIds = array_map(fn ($row) => (string) $row->id, DB::connection(TenantContext::CONNECTION)->select('select app_tenants_with_tenders() as id'));

        foreach ($tenantIds as $tenantId) {
            $tenants->run($tenantId, function () use (&$done, &$failed) {
                foreach (Tender::query()->orderBy('id')->get() as $tender) {
                    try {
                        Tx::run(function () use ($tender) {
                            $tender->forceFill([
                                'seal_secret' => Seal::rewrap($tender->seal_secret),
                                'public_token_secret' => Seal::rewrap($tender->public_token_secret),
                            ])->save();
                        });
                        $done++;
                    } catch (DecryptException) {
                        $failed++;
                        $this->error("Tender {$tender->id}: no key in APP_KEY or APP_PREVIOUS_KEYS opens it.");
                    }
                }
            });
        }

        $this->info("Tenders re-encrypted: {$done}; failed: {$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

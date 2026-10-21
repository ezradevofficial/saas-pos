<?php

namespace App\Core\Api\Console;

use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * API-01 (ADR 020): deletes stored idempotent responses past their 24
 * hours, in every tenant, through the narrow security-definer function
 * `app_prune_api_idempotency_keys()` (ADR 002: it deletes expired rows
 * only and returns how many). Runs hourly.
 */
class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'api:prune-idempotency';

    protected $description = 'Delete expired API idempotency records (API-01).';

    public function handle(): int
    {
        $deleted = (int) DB::connection(TenantContext::CONNECTION)
            ->selectOne('select public.app_prune_api_idempotency_keys(?::timestamptz) as deleted', [CarbonImmutable::now()->toIso8601String()])
            ->deleted;

        $this->info("Deleted {$deleted} expired idempotency records.");

        return self::SUCCESS;
    }
}

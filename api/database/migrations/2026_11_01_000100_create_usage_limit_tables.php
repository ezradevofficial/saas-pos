<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// LIM-01..LIM-05, TEN-01: usage metering and soft limits (docs/adr/018).
//
// - usage_meters: one row per tenant, UTC day and unit (outlet, device,
//   user, employee): the active count and the plan's allowance that day
//   (null: unlimited). Written by the daily `usage:meter` run; a second run
//   the same day updates the day's row. The history is kept: the runtime
//   role may not delete or truncate it. Overage billing (LIM-05) reads it.
// - usage_overages: one open record per tenant and unit while the count is
//   above the allowance (partial unique index). `started_at` opens the
//   7-day window (LIM-03); reminders and the block follow from it. Closed,
//   never deleted, when the count is back within the allowance.
return new class extends Migration
{
    private const UNITS = "'outlet', 'device', 'user', 'employee'";

    public function up(): void
    {
        Schema::create('usage_meters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->date('day');
            $table->string('unit', 20);
            $table->unsignedInteger('count');
            // Null: no limit on this unit that day.
            $table->unsignedInteger('allowed')->nullable();
            $table->timestampTz('metered_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'day', 'unit']);
        });

        Schema::create('usage_overages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('unit', 20);
            $table->unsignedInteger('allowed');
            $table->unsignedInteger('count');
            $table->timestampTz('started_at');
            // Daily reminders sent after the first warning (LIM-03).
            $table->unsignedSmallInteger('reminders_sent')->default(0);
            $table->timestampTz('last_notified_at')->nullable();
            $table->timestampTz('blocked_notified_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            // within_allowance (counted again) or unlimited (the plan no longer limits the unit).
            $table->string('close_reason', 30)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'started_at']);
        });

        DB::statement('alter table usage_meters add constraint usage_meters_unit_check check (unit in ('.self::UNITS.'))');
        DB::statement('alter table usage_overages add constraint usage_overages_unit_check check (unit in ('.self::UNITS.'))');
        DB::statement('alter table usage_overages add constraint usage_overages_over_check check (count > allowed)');
        DB::statement('alter table usage_overages add constraint usage_overages_closed_check check ((closed_at is null) = (close_reason is null))');
        DB::statement('create unique index usage_overages_one_open on usage_overages (tenant_id, unit) where closed_at is null');

        Rls::enable('usage_meters');
        Rls::enable('usage_overages');

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        // TEN-06: history, never deleted.
        DB::statement("revoke delete, truncate on usage_meters, usage_overages from {$runtimeRole}");
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_overages');
        Schema::dropIfExists('usage_meters');
    }
};

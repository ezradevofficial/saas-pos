<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// CUR-03: each company's exchange-rate history (reference rates from a feed,
// shop rates typed by authorised roles), append-only. 1 base = mid quote,
// numeric(18,8). CUR-07: alerts when a shop rate moves more than the
// company's tolerance from the previous rate. Companies choose a reference
// feed (none until the owner supplies its endpoint) and the tolerance.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->char('base', 3);
            $table->char('quote', 3);
            $table->string('kind', 10);
            $table->decimal('buy', 18, 8)->nullable();
            $table->decimal('sell', 18, 8)->nullable();
            $table->decimal('mid', 18, 8);
            $table->timestampTz('effective_at');
            $table->text('source');
            $table->foreignUuid('entered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('base')->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('quote')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['company_id', 'base', 'quote', 'kind', 'effective_at']);
            $table->index(['company_id', 'base', 'quote', 'effective_at']);
        });

        DB::statement("alter table exchange_rates add constraint exchange_rates_kind_check check (kind in ('reference', 'shop'))");
        DB::statement('alter table exchange_rates add constraint exchange_rates_pair_check check (base <> quote)');
        DB::statement('alter table exchange_rates add constraint exchange_rates_positive_check check (mid > 0 and (buy is null or buy > 0) and (sell is null or sell > 0))');

        Rls::enable('exchange_rates');

        Schema::create('rate_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('exchange_rate_id')->constrained()->restrictOnDelete();
            $table->string('pair', 7);
            $table->decimal('previous_mid', 18, 8);
            $table->decimal('new_mid', 18, 8);
            $table->decimal('change_percent', 9, 4);
            $table->foreignUuid('entered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
        });

        Rls::enable('rate_alerts');

        Schema::table('companies', function (Blueprint $table) {
            $table->string('rate_feed', 10)->default('none');
            $table->decimal('rate_tolerance_percent', 5, 2)->default(5);
        });

        DB::statement("alter table companies add constraint companies_rate_feed_check check (rate_feed in ('none', 'cbk', 'bcc'))");
        DB::statement('alter table companies add constraint companies_rate_tolerance_percent_check check (rate_tolerance_percent between 0 and 100)');
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['rate_feed', 'rate_tolerance_percent']);
        });

        Schema::dropIfExists('rate_alerts');
        Schema::dropIfExists('exchange_rates');
    }
};

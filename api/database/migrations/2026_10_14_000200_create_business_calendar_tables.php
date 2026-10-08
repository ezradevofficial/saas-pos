<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// WF-09, APR-05: the business calendar stage due times are counted in.
//
// business_hours: a company's working hours per weekday, in the company's
// time zone. No row: Monday to Friday 08:00-17:00.
//
// public_holidays: country-pack data (CP-01), global and read-only for the
// runtime role, loaded from country-packs/{CODE}/holidays.json by
// `country-packs:holidays`. A row is either a fixed date every year
// (month, day) or a single date. Dates nobody confirmed are never entered:
// they are listed in the file's todo list instead.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_hours', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->unique()->constrained()->restrictOnDelete();
            // {"mon": [["08:00", "17:00"]], ..., "sun": []}
            $table->jsonb('hours');
            $table->timestampsTz();
        });

        Rls::enable('business_hours');

        Schema::create('public_holidays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('country', 2);
            // Label key: holidays.{country}.{key}.
            $table->string('key', 60);
            $table->smallInteger('month')->nullable();
            $table->smallInteger('day')->nullable();
            $table->date('date')->nullable();
            // The years a yearly holiday applies (null: open).
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestampsTz();

            $table->index('country');
        });

        DB::statement("alter table public_holidays add constraint public_holidays_country_check check (country ~ '^[A-Z]{2}$')");
        DB::statement('alter table public_holidays add constraint public_holidays_kind_check check ((date is null) = (month is not null and day is not null))');
        DB::statement('alter table public_holidays add constraint public_holidays_month_check check (month is null or month between 1 and 12)');
        DB::statement('alter table public_holidays add constraint public_holidays_day_check check (day is null or day between 1 and 31)');

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("revoke insert, update, delete, truncate on public_holidays from {$runtimeRole}");
    }

    public function down(): void
    {
        Schema::dropIfExists('public_holidays');
        Schema::dropIfExists('business_hours');
    }
};

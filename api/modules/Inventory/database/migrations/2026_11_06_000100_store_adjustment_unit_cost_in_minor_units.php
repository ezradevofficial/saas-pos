<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// INV-08, ADR 003: an adjustment line's typed unit cost is money, so it is
// stored as bigint minor units with its currency (both set or both null),
// never as a decimal. New columns, backfilled from the old decimal (major
// units, in the adjustment's currency, at the tenant's decimals for it),
// then the old column is dropped.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");
        DB::statement('alter table inventory_adjustment_lines add column unit_cost_minor bigint, add column unit_cost_currency char(3)');

        DB::statement(<<<'SQL'
            update inventory_adjustment_lines l
            set unit_cost_minor = round(l.unit_cost * power(10, coalesce(tc.decimals, c.default_decimals, 2)))::bigint,
                unit_cost_currency = a.currency
            from inventory_adjustments a
            left join currencies c on c.code = a.currency
            left join tenant_currencies tc on tc.tenant_id = a.tenant_id and tc.code = a.currency
            where a.id = l.adjustment_id and l.unit_cost is not null
            SQL);

        DB::statement('alter table inventory_adjustment_lines drop column unit_cost');
        DB::statement(<<<'SQL'
            alter table inventory_adjustment_lines add constraint inventory_adjustment_lines_unit_cost_check check (
                (unit_cost_minor is null and unit_cost_currency is null)
                or (unit_cost_minor >= 0 and unit_cost_currency ~ '^[A-Z]{3}$')
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('alter table inventory_adjustment_lines drop constraint if exists inventory_adjustment_lines_unit_cost_check');
        DB::statement('alter table inventory_adjustment_lines add column unit_cost numeric(24, 6)');
        DB::statement(<<<'SQL'
            update inventory_adjustment_lines l
            set unit_cost = l.unit_cost_minor / power(10, coalesce(tc.decimals, c.default_decimals, 2))
            from inventory_adjustments a
            left join currencies c on c.code = a.currency
            left join tenant_currencies tc on tc.tenant_id = a.tenant_id and tc.code = a.currency
            where a.id = l.adjustment_id and l.unit_cost_minor is not null
            SQL);
        DB::statement('alter table inventory_adjustment_lines drop column unit_cost_minor, drop column unit_cost_currency');
    }
};

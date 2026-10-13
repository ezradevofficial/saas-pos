<?php

namespace Tests\Feature\Core\DataExport;

use App\Core\DataExport\ExportColumns;
use App\Core\DataExport\TenantTables;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * EXP-02: the reviewed secret deny-list. Every column of every exported
 * table whose name looks like a secret must be classified, as denied
 * (never exported) or allowed (with a reason). A new secret-like column
 * fails here until someone decides; until then the export leaves it out
 * (fail closed).
 */
class ExportColumnsTest extends TestCase
{
    use RefreshTenantDatabase;

    /** @return list<string> `table.column` of every column of every exported table */
    private function exportedTableColumns(): array
    {
        $tables = array_column(app(TenantTables::class)->all(), 'name');
        $this->assertContains('users', $tables);
        $this->assertContains('tenants', $tables);

        return array_map(fn ($row) => "{$row->table_name}.{$row->column_name}", DB::select(
            "select table_name, column_name from information_schema.columns where table_schema = 'public' and table_name = any(?::text[]) order by table_name, ordinal_position",
            ['{'.implode(',', $tables).'}'],
        ));
    }

    public function test_every_secret_like_column_of_an_exported_table_is_classified(): void
    {
        $columns = $this->exportedTableColumns();
        $secretLike = array_values(array_filter($columns, fn (string $key) => ExportColumns::isSecretLike(explode('.', $key, 2)[1])));
        $unclassified = array_values(array_filter($secretLike, fn (string $key) => ! isset(ExportColumns::DENIED[$key]) && ! isset(ExportColumns::ALLOWED[$key])));

        $this->assertNotEmpty($secretLike);
        $this->assertSame([], $unclassified, 'Secret-like columns not classified in App\Core\DataExport\ExportColumns (add each to DENIED or, with a reason, ALLOWED): '.implode(', ', $unclassified));
    }

    public function test_every_classified_column_exists_and_none_is_both_denied_and_allowed(): void
    {
        $columns = $this->exportedTableColumns();

        foreach ([...array_keys(ExportColumns::DENIED), ...array_keys(ExportColumns::ALLOWED)] as $key) {
            $this->assertContains($key, $columns, "{$key} is classified but no exported table has it: remove it from ExportColumns");
        }

        $this->assertSame([], array_values(array_intersect(array_keys(ExportColumns::DENIED), array_keys(ExportColumns::ALLOWED))));
    }

    public function test_the_known_secrets_are_denied_and_unreviewed_ones_stay_out(): void
    {
        foreach ([
            'users.password', 'users.two_factor_secret', 'device_secrets.secret', 'user_pins.pin_hash', 'user_pins.card_key',
            'payment_methods.secrets', 'payment_methods.callback_token', 'company_fiscal_settings.credentials',
            'automation_rules.webhook_secret', 'document_shares.token_hash', 'invitations.token_hash', 'devices.pairing_code_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, ExportColumns::DENIED);
        }

        $tables = app(TenantTables::class);
        $this->assertFalse($tables->exported('users', 'password'));
        $this->assertTrue($tables->exported('users', 'email'));
        $this->assertTrue($tables->exported('audit_logs', 'hash'));
        // Fail closed: a secret-like name nobody reviewed is left out.
        $this->assertFalse($tables->exported('some_new_table', 'api_key'));
        $this->assertFalse($tables->exported('some_new_table', 'refresh_token'));
    }
}

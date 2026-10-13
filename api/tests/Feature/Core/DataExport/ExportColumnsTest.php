<?php

namespace Tests\Feature\Core\DataExport;

use App\Core\DataExport\ExportColumns;
use App\Core\DataExport\TenantTables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
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

    /**
     * A model attribute cast `encrypted*` holds something the app chose to
     * hide even from the database: it must be classified, whatever its name.
     */
    public function test_every_encrypted_attribute_of_an_exported_table_is_classified(): void
    {
        $exported = array_column(app(TenantTables::class)->all(), 'name');
        $encrypted = [];

        foreach ([app_path(), base_path('modules')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/tests/')) {
                    continue;
                }

                $code = (string) file_get_contents($file->getPathname());

                if (! str_contains($code, 'encrypted') || preg_match('/^namespace\s+([^;]+);/m', $code, $ns) !== 1 || preg_match('/^(?:final\s+)?class\s+(\w+)/m', $code, $cls) !== 1) {
                    continue;
                }

                $class = $ns[1].'\\'.$cls[1];

                if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                    continue;
                }

                $model = new $class;

                foreach ($model->getCasts() as $attribute => $cast) {
                    if (is_string($cast) && (str_starts_with($cast, 'encrypted') || str_contains($cast, 'AsEncrypted')) && in_array($model->getTable(), $exported, true)) {
                        $encrypted[] = "{$model->getTable()}.{$attribute}";
                    }
                }
            }
        }

        $this->assertContains('payment_intents.phone', $encrypted, 'the scan finds encrypted casts');
        $unclassified = array_values(array_filter($encrypted, fn (string $key) => ! isset(ExportColumns::DENIED[$key]) && ! isset(ExportColumns::ALLOWED[$key])));
        $this->assertSame([], $unclassified, 'Encrypted attributes not classified in App\Core\DataExport\ExportColumns: '.implode(', ', $unclassified));
    }

    public function test_every_classified_column_exists_and_none_is_both_denied_and_allowed(): void
    {
        $columns = $this->exportedTableColumns();

        foreach ([...array_keys(ExportColumns::DENIED), ...array_keys(ExportColumns::ALLOWED), ...array_keys(ExportColumns::TRANSFORMS)] as $key) {
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
            'automation_webhook_deliveries.url', 'payment_intents.phone', 'billing_payments.redirect_url',
        ] as $key) {
            $this->assertArrayHasKey($key, ExportColumns::DENIED);
        }

        $tables = app(TenantTables::class);
        $this->assertFalse($tables->exported('users', 'password'));
        $this->assertTrue($tables->exported('users', 'email'));
        $this->assertTrue($tables->exported('audit_logs', 'hash'));
        $this->assertFalse($tables->exported('billing_payments', 'redirect_url'));
        // Fail closed: a secret-like name nobody reviewed is left out.
        $this->assertFalse($tables->exported('some_new_table', 'api_key'));
        $this->assertFalse($tables->exported('some_new_table', 'refresh_token'));
    }
}

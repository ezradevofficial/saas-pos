<?php

namespace App\Core\Observability\Keys;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * NFR-06: every database column holding a value encrypted with the
 * application key, for `app:reencrypt` (APP_KEY rotation).
 *
 * - Found from the models: every attribute cast `encrypted`,
 *   `encrypted:<type>` or `AsEncrypted*` of a concrete model in app/ or
 *   modules/. A new encrypted cast is picked up without editing anything.
 * - Plus the values a module encrypts by hand with `Crypt::encryptString`
 *   (MANUAL). `EncryptedColumnsTest` fails when code outside the reviewed
 *   files calls Crypt, so a new hand-encrypted column cannot be missed.
 *
 * Every one of these is a Laravel `encryptString` payload (the casts store
 * arrays as encrypted JSON), so re-encrypting never needs to know what the
 * value is.
 */
final class EncryptedColumns
{
    /** Columns encrypted by hand (Crypt::encryptString), by table. */
    public const MANUAL = [
        // Procurement (ADR 019): a tender's sealing key and its notice token, Tenders and Seal.
        'procurement_tenders' => ['seal_secret', 'public_token_secret'],
    ];

    /**
     * Files that call Crypt (or encrypt()/decrypt()) on purpose, and why; checked by EncryptedColumnsTest.
     *
     * @var array<string, string>
     */
    public const CRYPT_CALLERS = [
        'app/Core/Platform/PlatformAuth.php' => 'a staff sign-in challenge handed to the browser for a few minutes, never stored',
        'modules/Procurement/Tenders/Tenders.php' => 'procurement_tenders.public_token_secret (MANUAL)',
        'modules/Procurement/Tenders/Seal.php' => 'procurement_tenders.seal_secret (MANUAL); bids are sealed with the tender key, not the app key',
    ];

    /** @var list<array{table: string, columns: list<string>, connection: string, key: string, tenant: bool}>|null */
    private ?array $columns = null;

    /** @return list<array{table: string, columns: list<string>, connection: string, key: string, tenant: bool}> sorted by table */
    public function all(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $tables = [];

        foreach ($this->models() as $model) {
            foreach ($model->getCasts() as $attribute => $cast) {
                if (is_string($cast) && (str_starts_with($cast, 'encrypted') || str_contains($cast, 'AsEncrypted'))) {
                    $table = $model->getTable();
                    $tables[$table] ??= ['table' => $table, 'columns' => [], 'connection' => $model->getConnectionName() ?? (string) config('database.default'), 'key' => $model->getKeyName()];
                    $tables[$table]['columns'][] = $attribute;
                }
            }
        }

        foreach (self::MANUAL as $table => $columns) {
            $tables[$table] ??= ['table' => $table, 'columns' => [], 'connection' => (string) config('database.default'), 'key' => 'id'];
            array_push($tables[$table]['columns'], ...$columns);
        }

        ksort($tables);
        $result = [];

        foreach ($tables as $entry) {
            $entry['columns'] = array_values(array_unique($entry['columns']));
            sort($entry['columns']);
            $present = $this->presentColumns($entry['connection'], $entry['table']);

            // A module whose tables are not migrated here holds nothing to rotate.
            if ($present === []) {
                continue;
            }

            $entry['columns'] = array_values(array_intersect($entry['columns'], $present));
            $entry['tenant'] = in_array('tenant_id', $present, true);
            $result[] = $entry;
        }

        return $this->columns = $result;
    }

    /** @return list<string> the table's column names, empty when it does not exist */
    private function presentColumns(string $connection, string $table): array
    {
        return array_map(fn (object $row) => (string) $row->column_name, DB::connection($connection)->select(
            'select column_name from information_schema.columns where table_schema = current_schema() and table_name = ?', [$table],
        ));
    }

    /** @return iterable<Model> one instance of every concrete model that mentions "encrypted" */
    private function models(): iterable
    {
        foreach ([app_path(), base_path('modules')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/tests/') || str_contains($file->getPathname(), '/database/')) {
                    continue;
                }

                $code = (string) file_get_contents($file->getPathname());

                if (! str_contains($code, 'ncrypted') || preg_match('/^namespace\s+([^;]+);/m', $code, $ns) !== 1 || preg_match('/^(?:final\s+)?class\s+(\w+)/m', $code, $cls) !== 1) {
                    continue;
                }

                $class = $ns[1].'\\'.$cls[1];

                if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                    yield new $class;
                }
            }
        }
    }
}

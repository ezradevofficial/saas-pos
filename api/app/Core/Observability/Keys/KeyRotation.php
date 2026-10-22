<?php

namespace App\Core\Observability\Keys;

use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * NFR-06: after an APP_KEY rotation (the old key in APP_PREVIOUS_KEYS),
 * re-encrypt every encrypted column (EncryptedColumns) with the current key.
 *
 * - Tenant tables: tenant by tenant, every tenant whatever its status (the
 *   security-definer `app_tenants_for_key_rotation()`, ids only), each under
 *   its own row-level security on the runtime connection. Platform tables:
 *   on the platform connection (ADR 013). The owner's credentials are never
 *   used.
 * - In chunks ordered by primary key; each chunk is one transaction.
 * - Idempotent and resumable: a value the current key already opens is left
 *   as it is, so a second run changes nothing and an interrupted run simply
 *   carries on. A row is written only if its value is still the one read
 *   (compare and set), so a value saved meanwhile is never overwritten.
 * - Counts only: values, ciphertexts and row ids are never reported.
 */
final class KeyRotation
{
    /** @var array<string, array{rotated: int, current: int, failed: int}> per "table.column" */
    private array $counts = [];

    private int $budget = PHP_INT_MAX;

    public function __construct(private readonly EncryptedColumns $columns, private readonly TenantContext $tenants) {}

    /**
     * @param  ?int  $stopAfter  re-encrypt at most this many values, then stop (a staged run; run again to go on)
     * @return array{counts: array<string, array{rotated: int, current: int, failed: int}>, tenants: int, stopped: bool}
     */
    public function run(int $chunk = 500, bool $dryRun = false, ?int $stopAfter = null): array
    {
        if ($this->tenants->id() !== null) {
            throw new RuntimeException('Run the key rotation outside any tenant: it enters each tenant itself.');
        }

        $current = $this->currentOnly();
        $any = app('encrypter');
        $this->counts = [];
        $this->budget = $stopAfter ?? PHP_INT_MAX;
        $tables = $this->columns->all();

        foreach ($tables as $table) {
            // Row-level security follows the tenant on the runtime connection only (ADR 002).
            if ($table['tenant'] && $table['connection'] !== TenantContext::CONNECTION) {
                throw new RuntimeException("{$table['table']} is a tenant table on another connection: it would not be read under the tenant's row-level security.");
            }

            foreach ($table['columns'] as $column) {
                $this->counts["{$table['table']}.{$column}"] = ['rotated' => 0, 'current' => 0, 'failed' => 0];
            }
        }

        $tenantIds = array_map(
            fn (object $row) => (string) $row->id,
            DB::connection(TenantContext::CONNECTION)->select('select app_tenants_for_key_rotation() as id'),
        );

        foreach ($tables as $table) {
            if ($table['tenant']) {
                foreach ($tenantIds as $tenantId) {
                    $this->tenants->run($tenantId, fn () => $this->table($table, $chunk, $dryRun, $current, $any));

                    if ($this->budget <= 0) {
                        break 2;
                    }
                }
            } else {
                $this->table($table, $chunk, $dryRun, $current, $any);

                if ($this->budget <= 0) {
                    break;
                }
            }
        }

        return ['counts' => $this->counts, 'tenants' => count($tenantIds), 'stopped' => $this->budget <= 0];
    }

    /** @param  array{table: string, columns: list<string>, connection: string, key: string, tenant: bool}  $table */
    private function table(array $table, int $chunk, bool $dryRun, Encrypter $current, Encrypter $any): void
    {
        $db = DB::connection($table['connection']);
        $key = $table['key'];
        $after = null;

        do {
            $rows = $db->table($table['table'])
                ->select([$key, ...$table['columns']])
                ->where(function ($query) use ($table) {
                    foreach ($table['columns'] as $column) {
                        $query->orWhereNotNull($column);
                    }
                })
                ->when($after !== null, fn ($query) => $query->where($key, '>', $after))
                ->orderBy($key)
                ->limit($chunk)
                ->get();

            $db->transaction(function () use ($rows, $table, $key, $db, $dryRun, $current, $any) {
                foreach ($rows as $row) {
                    foreach ($table['columns'] as $column) {
                        $value = $row->{$column};

                        if ($value === null || $this->budget <= 0) {
                            continue;
                        }

                        $name = "{$table['table']}.{$column}";
                        $rewrapped = $this->rewrap((string) $value, $current, $any);

                        if ($rewrapped === false) {
                            $this->counts[$name]['failed']++;

                            continue;
                        }

                        if ($rewrapped === null) {
                            $this->counts[$name]['current']++;

                            continue;
                        }

                        // Compare and set: a value saved since it was read is already under the current key.
                        $written = $dryRun ? 1 : $db->table($table['table'])->where($key, $row->{$key})->where($column, $value)->update([$column => $rewrapped]);

                        if ($written === 1) {
                            $this->counts[$name]['rotated']++;
                            $this->budget--;
                        } else {
                            $this->counts[$name]['current']++;
                        }
                    }
                }
            });

            $after = $rows->last()?->{$key};
        } while ($rows->count() === $chunk && $this->budget > 0);
    }

    /** The value under the current key; null when it already is, false when no key opens it. */
    private function rewrap(string $value, Encrypter $current, Encrypter $any): string|false|null
    {
        try {
            $current->decryptString($value);

            return null;
        } catch (DecryptException) {
            // Not the current key: try the previous ones below.
        }

        try {
            return $current->encryptString($any->decryptString($value));
        } catch (DecryptException) {
            return false;
        }
    }

    /** An encrypter with the current key only (the app's also tries APP_PREVIOUS_KEYS). */
    private function currentOnly(): Encrypter
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('APP_KEY is not set.');
        }

        if (Str::startsWith($key, 'base64:')) {
            $key = base64_decode(Str::after($key, 'base64:'));
        }

        return new Encrypter($key, (string) config('app.cipher'));
    }
}

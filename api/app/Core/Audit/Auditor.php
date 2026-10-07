<?php

namespace App\Core\Audit;

use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Appends entries to the current tenant's hash-chained audit log and
 * verifies chains (AUD-01..AUD-03).
 *
 * hash = sha256(prev_hash . canonicalJson(fields)), where fields are the
 * columns listed in HASHED, normalised by normalise(). The same function
 * runs on the values written and on the values PostgreSQL returns, so the
 * hash survives jsonb key reordering and number formatting, trimmed
 * fractional seconds, time zone output and inet formatting.
 */
class Auditor
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Entry fields covered by the hash, in canonical (sorted) order. */
    public const HASHED = [
        'action', 'after', 'auditable_id', 'auditable_type', 'before', 'device_id', 'device_time',
        'ip', 'location_id', 'module', 'occurred_at', 'on_behalf_of_user_id', 'seq', 'tenant_id',
        'user_agent', 'user_id',
    ];

    /** Fields a caller may set through $extra, overriding AuditContext. */
    private const OVERRIDABLE = [
        'module', 'device_time', 'user_id', 'on_behalf_of_user_id',
        'ip', 'user_agent', 'device_id', 'location_id',
    ];

    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    private const USER_AGENT_MAX = 1024;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuditContext $context,
    ) {}

    /**
     * Append an entry to the current tenant's chain. Runs in a transaction
     * (a savepoint when nested, so it commits or rolls back with the change
     * it records) and holds the chain head row lock until commit.
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        array $extra = [],
    ): AuditEntry {
        $tenantId = $this->tenants->require();

        $fields = array_merge([
            'module' => Str::before($action, '.'),
            'occurred_at' => CarbonImmutable::now(),
            'device_time' => $this->context->deviceTime(),
            'user_id' => $this->context->userId(),
            'on_behalf_of_user_id' => $this->context->onBehalfOfUserId(),
            'ip' => $this->context->ip(),
            'user_agent' => $this->context->userAgent(),
            'device_id' => $this->context->deviceId(),
            'location_id' => $this->context->locationId(),
        ], array_intersect_key($extra, array_flip(self::OVERRIDABLE)), [
            'tenant_id' => $tenantId,
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey() === null ? null : (string) $subject->getKey(),
            'before' => $before,
            'after' => $after,
        ]);

        if (is_string($fields['user_agent'])) {
            $fields['user_agent'] = mb_substr(
                str_replace("\0", '', mb_scrub($fields['user_agent'], 'UTF-8')), 0, self::USER_AGENT_MAX,
            );
        }

        return $this->connection()->transaction(function (Connection $db) use ($tenantId, $fields) {
            // First use: create the head; then lock it for this append.
            $db->insert(
                'insert into audit_chain_heads (tenant_id, seq, hash) values (?, 0, ?) on conflict (tenant_id) do nothing',
                [$tenantId, self::GENESIS_HASH],
            );
            $head = $db->selectOne(
                'select seq, hash from audit_chain_heads where tenant_id = ? for update',
                [$tenantId],
            );

            $fields['seq'] = (int) $head->seq + 1;
            $row = self::normalise($fields);
            $row['prev_hash'] = $head->hash;
            $row['hash'] = self::hash($head->hash, $row);
            $row['id'] = (string) Str::uuid7();

            $db->table('audit_logs')->insert(array_merge($row, [
                'before' => self::jsonColumn($row['before']),
                'after' => self::jsonColumn($row['after']),
            ]));
            $db->update(
                'update audit_chain_heads set seq = ?, hash = ? where tenant_id = ?',
                [$row['seq'], $row['hash'], $tenantId],
            );

            return (new AuditEntry)->newFromBuilder(array_merge($row, [
                'before' => self::jsonColumn($row['before']),
                'after' => self::jsonColumn($row['after']),
            ]));
        });
    }

    /**
     * First seq at which $tenantId's chain is broken (bad hash, bad link,
     * gap, or entries missing up to the head), or null when intact. Runs
     * inside the tenant's own context: RLS is never bypassed.
     *
     * The head is read first and only entries up to its seq are walked: an
     * entry and its head update commit together, so every entry up to the
     * head is visible, and entries appended while verifying are ignored.
     * Then any entry above the current head is a break at head seq + 1
     * (a head moved back to hide entries; the database also refuses that).
     */
    public function verify(string $tenantId): ?int
    {
        return $this->tenants->run($tenantId, function () use ($tenantId) {
            $db = $this->connection();
            $head = $this->chainHead($db, $tenantId);
            $headSeq = $head === null ? 0 : (int) $head->seq;
            $headHash = $head === null ? self::GENESIS_HASH : $head->hash;
            $expectedSeq = 1;
            $prevHash = self::GENESIS_HASH;

            $entries = $db->table('audit_logs')
                ->where('tenant_id', $tenantId)
                ->where('seq', '<=', $headSeq)
                ->lazyById(500, 'seq');

            foreach ($entries as $entry) {
                $fields = self::normalise((array) $entry);

                if ($fields['seq'] !== $expectedSeq
                    || $entry->prev_hash !== $prevHash
                    || ! hash_equals(self::hash($prevHash, $fields), $entry->hash)) {
                    return $expectedSeq;
                }

                $prevHash = $entry->hash;
                $expectedSeq++;
            }

            // The walk must end exactly at the head.
            if ($expectedSeq - 1 !== $headSeq || $prevHash !== $headHash) {
                return $expectedSeq;
            }

            // Entries beyond the head: a head moved back (or removed) hides
            // them from the walk. The head is read again in the same
            // statement, so an entry appended meanwhile (committed with its
            // head) is never a false alarm.
            $beyond = $db->selectOne(<<<'SQL'
                select h.seq as head_seq,
                       exists (select 1 from audit_logs l where l.tenant_id = ? and l.seq > h.seq) as hidden
                from (select coalesce((select seq from audit_chain_heads where tenant_id = ?), 0) as seq) h
                SQL, [$tenantId, $tenantId]);

            if ($beyond->hidden) {
                return (int) $beyond->head_seq + 1;
            }

            return null;
        });
    }

    /** The tenant's chain head row, or null before the first entry. */
    protected function chainHead(Connection $db, string $tenantId): ?object
    {
        return $db->table('audit_chain_heads')->where('tenant_id', $tenantId)->first();
    }

    /**
     * JSON with keys sorted recursively (lists keep their order), unescaped
     * slashes and unicode, no whitespace.
     */
    public static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::sortKeys($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private static function hash(string $prevHash, array $fields): string
    {
        return hash('sha256', $prevHash.self::canonicalJson(array_intersect_key($fields, array_flip(self::HASHED))));
    }

    /**
     * Bring a field set to one form, whether it comes from PHP (write) or
     * from a PostgreSQL row (verify).
     *
     * @return array<string, mixed>
     */
    private static function normalise(array $fields): array
    {
        $out = [];

        foreach (self::HASHED as $key) {
            $value = $fields[$key] ?? null;

            $out[$key] = match ($key) {
                'seq' => (int) $value,
                'occurred_at', 'device_time' => self::timestamp($value),
                'before', 'after' => self::json($value),
                'ip' => self::ip($value),
                'tenant_id', 'user_id', 'on_behalf_of_user_id', 'auditable_id', 'device_id', 'location_id' => $value === null ? null : strtolower((string) $value),
                default => $value === null ? null : (string) $value,
            };
        }

        return $out;
    }

    /** UTC, microseconds, as stored by timestamptz(6). */
    private static function timestamp(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value);

        return $date->utc()->format(self::TIMESTAMP_FORMAT);
    }

    /** Decoded JSON exactly as jsonb hands it back (string from the DB, array from PHP). */
    private static function json(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $encoded = is_string($value) ? $value : json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        return json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Canonical text form of an address, as inet prints a host address; invalid input is dropped. */
    private static function ip(?string $value): ?string
    {
        if ($value === null || filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return inet_ntop(inet_pton($value));
    }

    private static function jsonColumn(?array $value): ?string
    {
        return $value === null
            ? null
            : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sortKeys(...), $value);
    }

    private function connection(): Connection
    {
        return DB::connection(TenantContext::CONNECTION);
    }
}

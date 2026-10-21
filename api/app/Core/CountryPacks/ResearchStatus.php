<?php

namespace App\Core\CountryPacks;

use InvalidArgumentException;

/**
 * CP-01, CP-02: the status of one value row of a country pack (a tax code
 * period, a payroll component period, a leave type, an identifier type).
 *
 * - `confirmed`: confirmed by the owner (or an accountant) from an
 *   official source. `needs_confirmation: false`.
 * - `researched`: taken from public research (docs/country-packs-research-2026-10.md),
 *   not yet confirmed. `needs_confirmation: false`, with `source` (a list
 *   of https URLs), `researched_on` (a date) and `confidence` (`high` or
 *   `medium`). Usable: what uses it records that it did, and the web app
 *   shows the "from public research" notice.
 * - `needs_confirmation`: no usable figure. `needs_confirmation: true`;
 *   figures stay null and block what needs them. A low-confidence research
 *   result is never entered: the row stays here, and may say
 *   `confidence: low` with its `source` for the reader.
 *
 * A row without `status` is read from `needs_confirmation` (packs written
 * before the status existed stay valid). A researched row may list in
 * `assumed` the fields whose value (or absence: CNSS `base_cap_minor`, no
 * ceiling) is a default pending confirmation, or a named assumption
 * (`residency`: KE personal relief for every employee); they are shown
 * on the statutory rates page and recorded on each run.
 */
final class ResearchStatus
{
    public const CONFIRMED = 'confirmed';

    public const RESEARCHED = 'researched';

    public const NEEDS_CONFIRMATION = 'needs_confirmation';

    public const STATUSES = [self::CONFIRMED, self::RESEARCHED, self::NEEDS_CONFIRMATION];

    /** Research confidence levels that are entered and used; `low` never is. */
    public const USABLE_CONFIDENCE = ['high', 'medium'];

    public const CONFIDENCE = ['high', 'medium', 'low'];

    /** The row's status (explicit, else from `needs_confirmation`). */
    public static function of(array $row): string
    {
        $status = $row['status'] ?? null;

        if (in_array($status, self::STATUSES, true)) {
            return $status;
        }

        return ($row['needs_confirmation'] ?? true) === false ? self::CONFIRMED : self::NEEDS_CONFIRMATION;
    }

    /** True when the row's values may be used: confirmed, or researched at high or medium confidence. */
    public static function usable(array $row): bool
    {
        if (($row['needs_confirmation'] ?? true) !== false) {
            return false;
        }

        return match (self::of($row)) {
            self::CONFIRMED => true,
            self::RESEARCHED => in_array($row['confidence'] ?? null, self::USABLE_CONFIDENCE, true),
            default => false,
        };
    }

    /**
     * What a document or run records about a researched row it used, or
     * null when the row is not researched.
     *
     * @return array{confidence: string, researched_on: string, source: list<string>, assumed: list<string>}|null
     */
    public static function research(array $row): ?array
    {
        if (self::of($row) !== self::RESEARCHED) {
            return null;
        }

        return [
            'confidence' => (string) ($row['confidence'] ?? ''),
            'researched_on' => (string) ($row['researched_on'] ?? ''),
            'source' => array_values(array_map('strval', (array) ($row['source'] ?? []))),
            'assumed' => array_values(array_map('strval', (array) ($row['assumed'] ?? []))),
        ];
    }

    /**
     * Validate the status fields of one row ($where names it in the error).
     *
     * @throws InvalidArgumentException
     */
    public static function check(array $row, string $where): void
    {
        $status = $row['status'] ?? null;
        $needs = $row['needs_confirmation'] ?? null;

        if ($status !== null && ! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("{$where}: status must be one of ".implode(', ', self::STATUSES).'.');
        }

        if (array_key_exists('confidence', $row) && ! in_array($row['confidence'], self::CONFIDENCE, true)) {
            throw new InvalidArgumentException("{$where}: confidence must be high, medium or low.");
        }

        if (array_key_exists('source', $row)) {
            $source = $row['source'];

            if (! is_array($source) || ! array_is_list($source) || $source === []) {
                throw new InvalidArgumentException("{$where}: source must be a non-empty list of URLs.");
            }

            foreach ($source as $url) {
                if (! is_string($url) || preg_match('#^https://[^\s]+\z#', $url) !== 1) {
                    throw new InvalidArgumentException("{$where}: each source must be an https URL.");
                }
            }
        }

        if (array_key_exists('researched_on', $row) && (! is_string($row['researched_on']) || preg_match('/^\d{4}-\d{2}-\d{2}\z/', $row['researched_on']) !== 1)) {
            throw new InvalidArgumentException("{$where}: researched_on must be a date (YYYY-MM-DD).");
        }

        if ($status === self::RESEARCHED) {
            if ($needs !== false) {
                throw new InvalidArgumentException("{$where}: a researched value has needs_confirmation false.");
            }

            if (! in_array($row['confidence'] ?? null, self::USABLE_CONFIDENCE, true)) {
                throw new InvalidArgumentException("{$where}: a researched value has confidence high or medium; low-confidence values are not entered.");
            }

            if (! array_key_exists('source', $row) || ! array_key_exists('researched_on', $row)) {
                throw new InvalidArgumentException("{$where}: a researched value has its source and researched_on.");
            }
        } elseif (($row['confidence'] ?? null) !== null && $row['confidence'] !== 'low') {
            throw new InvalidArgumentException("{$where}: confidence high or medium is only for a researched value.");
        }

        if ($status === self::CONFIRMED && $needs !== false) {
            throw new InvalidArgumentException("{$where}: a confirmed value has needs_confirmation false.");
        }

        if ($status === self::NEEDS_CONFIRMATION && $needs !== true) {
            throw new InvalidArgumentException("{$where}: a value that needs confirmation has needs_confirmation true.");
        }

        if (($row['confidence'] ?? null) === 'low' && $needs !== true) {
            throw new InvalidArgumentException("{$where}: a low-confidence value is not entered; it stays needs_confirmation.");
        }

        if (array_key_exists('assumed', $row)) {
            $assumed = $row['assumed'];

            if ($status !== self::RESEARCHED || ! is_array($assumed) || ! array_is_list($assumed) || $assumed === []) {
                throw new InvalidArgumentException("{$where}: assumed lists the fields of a researched value that are defaults pending confirmation.");
            }

            // A field the row has, or an absent optional one whose absence is the assumption (no ceiling).
            foreach ($assumed as $field) {
                if (! is_string($field) || preg_match('/^[a-z][a-z0-9_]{0,59}\z/', $field) !== 1) {
                    throw new InvalidArgumentException("{$where}: assumed lists field names (lower case, digits and _).");
                }
            }
        }
    }

    /** Status fields of a pack row (kept out of the figures a component reads). */
    public const FIELDS = ['status', 'confidence', 'researched_on', 'source', 'assumed'];
}

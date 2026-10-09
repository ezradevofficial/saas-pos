<?php

namespace App\Core\Configuration;

/**
 * LAY-07: upgrade safety. A stored layout names the entries it places
 * (fields, columns, widgets, menu items) by id; the platform's catalogue
 * is what exists today. Merging the two never breaks a customer layout:
 *
 * - an entry the layout names that is no longer in the catalogue is
 *   skipped (and so is a second mention of the same id);
 * - an entry the layout names keeps its place and its layout settings
 *   (LAYOUT_KEYS plus the kind's own, ConfigKind::layoutKeys()) over the
 *   catalogue's entry; everything else (locked, permission, required,
 *   module, ...) always comes from the catalogue, so a stored layout can
 *   never unlock a field or drop its permission;
 * - a catalogue entry the layout does not mention (added by a platform
 *   update) appears in its default position: right after the entry its
 *   `after` names when the layout has it, else at the end; with the
 *   HIDDEN rule (the kind's choice, or the entry's own `when_new`) it is
 *   added with `hidden: true` instead, so designers list it but nothing
 *   shows it until someone places it.
 *
 * `after` and `when_new` are catalogue hints and never reach the result.
 * Malformed parts of a stored layout (a group or entry that is not an
 * object) are skipped, never fatal.
 */
final class CatalogueMerge
{
    public const APPEND = 'append';

    public const HIDDEN = 'hidden';

    private const HINTS = ['after', 'when_new', 'group'];

    /** What a stored entry may set over its catalogue entry (with the id and hidden keys). */
    public const LAYOUT_KEYS = ['order', 'width', 'height', 'x', 'y', 'hidden', 'label', 'help', 'group', 'position'];

    /**
     * @param  list<array<string, mixed>>  $stored  entries the layout names, in its order
     * @param  list<array<string, mixed>>  $catalogue  entries that exist now, with their defaults
     * @param  self::APPEND|self::HIDDEN  $newEntries  the kind's rule for entries the layout does not mention
     * @param  list<string>  $layoutKeys  what a stored entry may set (the kind's ConfigKind::layoutKeys())
     * @return list<array<string, mixed>>
     */
    public static function entries(array $stored, array $catalogue, string $newEntries = self::APPEND, string $id = 'id', string $hidden = 'hidden', array $layoutKeys = self::LAYOUT_KEYS): array
    {
        $known = self::index($catalogue, $id);
        $result = self::keep($stored, $known, $id, [...$layoutKeys, $id, $hidden]);
        $placed = array_flip(array_map('strval', array_column($result, $id)));
        $anchors = [];

        foreach ($catalogue as $entry) {
            if (! isset($entry[$id]) || isset($placed[(string) $entry[$id]])) {
                continue;
            }

            $placed[(string) $entry[$id]] = true;
            $result = self::place($result, $entry, $newEntries, $id, $hidden, $anchors);
        }

        return $result;
    }

    /**
     * The same for a layout of groups (form sections, menu groups), each
     * listing entries under $items. Entries are matched across all groups;
     * an unknown entry is skipped wherever it is; a new entry goes to the
     * group its `group` hint names, else the group holding its `after`
     * anchor, else the first group (a new group with $newGroup's settings
     * when the layout has none), placed as entries() places it.
     *
     * @param  list<array<string, mixed>>  $groups
     * @param  list<array<string, mixed>>  $catalogue
     * @param  array<string, mixed>  $newGroup  the group to create when the layout has none (must carry its id)
     * @param  list<string>  $layoutKeys  what a stored entry may set (the kind's ConfigKind::layoutKeys())
     * @return list<array<string, mixed>>
     */
    public static function grouped(array $groups, array $catalogue, string $items = 'fields', string $newEntries = self::APPEND, array $newGroup = ['id' => 'main'], string $id = 'id', string $hidden = 'hidden', array $layoutKeys = self::LAYOUT_KEYS): array
    {
        $known = self::index($catalogue, $id);
        $allowed = [...$layoutKeys, $id, $hidden];
        $placed = [];
        // A group that is not an object cannot hold entries: skipped.
        $groups = array_values(array_filter($groups, 'is_array'));

        foreach ($groups as $g => $group) {
            $entries = is_array($group[$items] ?? null) ? $group[$items] : [];
            $kept = self::keep(array_values(array_filter($entries, fn ($e) => is_array($e) && ! isset($placed[is_scalar($e[$id] ?? null) ? (string) $e[$id] : '']))), $known, $id, $allowed);

            foreach ($kept as $entry) {
                $placed[(string) $entry[$id]] = true;
            }

            $groups[$g][$items] = $kept;
        }

        $anchors = [];

        foreach ($catalogue as $entry) {
            if (! isset($entry[$id]) || isset($placed[(string) $entry[$id]])) {
                continue;
            }

            $placed[(string) $entry[$id]] = true;

            if ($groups === []) {
                $groups[] = [...$newGroup, $items => []];
            }

            $target = self::groupFor($groups, $entry, $items, $id, $anchors);

            $groups[$target][$items] = self::place($groups[$target][$items], $entry, $newEntries, $id, $hidden, $anchors);
        }

        return $groups;
    }

    /**
     * The group a new entry goes to: the one its `group` hint names, else
     * the one holding its `after` anchor, else the first.
     */
    private static function groupFor(array $groups, array $entry, string $items, string $id, array $anchors): int
    {
        foreach ($groups as $g => $group) {
            if (isset($entry['group'], $group[$id]) && $group[$id] === $entry['group']) {
                return $g;
            }
        }

        if (isset($entry['after']) && is_scalar($entry['after'])) {
            $anchor = $anchors[(string) $entry['after']] ?? (string) $entry['after'];

            foreach ($groups as $g => $group) {
                if (in_array($anchor, array_map(fn ($e) => (string) ($e[$id] ?? ''), $group[$items]), true)) {
                    return $g;
                }
            }
        }

        return 0;
    }

    /** @return array<string, array<string, mixed>> catalogue entries by id */
    private static function index(array $catalogue, string $id): array
    {
        $known = [];

        foreach ($catalogue as $entry) {
            if (is_array($entry) && isset($entry[$id]) && is_scalar($entry[$id])) {
                $known[(string) $entry[$id]] ??= $entry;
            }
        }

        return $known;
    }

    /**
     * Stored entries that still exist, once each: the catalogue entry with
     * only the stored $allowed (layout) keys laid over it.
     */
    private static function keep(array $stored, array $known, string $id, array $allowed): array
    {
        $allowed = array_flip($allowed);

        $result = [];
        $seen = [];

        foreach ($stored as $entry) {
            $key = is_array($entry) && isset($entry[$id]) && is_scalar($entry[$id]) ? (string) $entry[$id] : null;

            if ($key === null || ! isset($known[$key]) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = [...self::withoutHints($known[$key]), ...array_intersect_key($entry, $allowed)];
        }

        return $result;
    }

    /**
     * $entries with the new catalogue $entry in its default position.
     * $anchors remembers, per `after` anchor, the last entry placed after
     * it, so several new entries after one anchor keep catalogue order.
     */
    private static function place(array $entries, array $entry, string $rule, string $id, string $hidden, array &$anchors): array
    {
        $new = self::withoutHints($entry);

        if (($entry['when_new'] ?? $rule) === self::HIDDEN) {
            $new[$hidden] = true;
        }

        $after = isset($entry['after']) && is_scalar($entry['after']) ? (string) $entry['after'] : null;

        if ($after !== null) {
            $behind = $anchors[$after] ?? $after;

            foreach ($entries as $index => $existing) {
                if ((string) ($existing[$id] ?? '') === $behind) {
                    array_splice($entries, $index + 1, 0, [$new]);
                    $anchors[$after] = (string) $new[$id];

                    return $entries;
                }
            }
        }

        $entries[] = $new;

        return $entries;
    }

    private static function withoutHints(array $entry): array
    {
        return array_diff_key($entry, array_flip(self::HINTS));
    }
}

<?php

namespace App\Core\Observability\Backups;

/**
 * NFR-07: compares a restored database with its source. The restore is a
 * point in time and the source has moved on since, so a table may hold
 * fewer rows in the restore (up to the tolerance), never more; the schema
 * version (the last migration) and the set of tables must match exactly.
 */
final class BackupComparison
{
    /**
     * @param  array{migration: ?string, tables: array<string, int>}  $source
     * @param  array{migration: ?string, tables: array<string, int>}  $restored
     * @return list<string> problems, empty when the restore checks out
     */
    public static function compare(array $source, array $restored, float $tolerance): array
    {
        $problems = [];

        if ($source['migration'] !== $restored['migration']) {
            $problems[] = sprintf('schema version differs: source %s, restore %s', $source['migration'] ?? 'none', $restored['migration'] ?? 'none');
        }

        foreach (array_diff_key($source['tables'], $restored['tables']) as $table => $count) {
            $problems[] = "table {$table} is missing from the restore";
        }

        foreach (array_diff_key($restored['tables'], $source['tables']) as $table => $count) {
            $problems[] = "table {$table} exists only in the restore";
        }

        foreach (array_intersect_key($source['tables'], $restored['tables']) as $table => $sourceCount) {
            $restoredCount = $restored['tables'][$table];

            if ($restoredCount > $sourceCount) {
                $problems[] = "table {$table} has more rows in the restore ({$restoredCount}) than the source ({$sourceCount})";
            } elseif ($sourceCount > 0 && ($sourceCount - $restoredCount) / $sourceCount > $tolerance) {
                $problems[] = sprintf('table %s has %d rows in the restore, %d in the source (more than %s%% fewer)', $table, $restoredCount, $sourceCount, rtrim(rtrim(number_format($tolerance * 100, 2), '0'), '.'));
            }
        }

        return $problems;
    }
}

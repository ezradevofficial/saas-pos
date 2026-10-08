<?php

namespace App\Core\Automation\Runtime;

use App\Core\Automation\Actions\WebhookAction;
use Illuminate\Support\Str;

/**
 * A rule's action list as stored and as shown (AUTO-03):
 *
 * - every action has a stable `id` (given on save when missing), returned
 *   in output and used to match actions across edits;
 * - a webhook URL is write-only: output (API and audit) shows `url_display`
 *   (scheme, host and path, no query string or fragment, which may carry a
 *   token) and `has_url`; an edit may leave the URL out (or send
 *   `keep_url: true`) to keep the one stored for that action, matched by
 *   id, else by position; sending a `url` replaces it.
 */
final class ActionList
{
    private const ID = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * The incoming actions with ids added and kept webhook URLs filled in
     * from $stored.
     *
     * @param  array<int, mixed>  $incoming
     * @param  array<int, mixed>  $stored
     * @return array<int, mixed>
     */
    public static function merge(array $incoming, array $stored = []): array
    {
        $storedById = [];

        foreach ($stored as $action) {
            if (is_array($action) && is_string($action['id'] ?? null)) {
                $storedById[$action['id']] = $action;
            }
        }

        foreach ($incoming as $i => $action) {
            if (! is_array($action)) {
                continue;
            }

            if (($action['type'] ?? null) === WebhookAction::KEY && (! array_key_exists('url', $action) || ($action['keep_url'] ?? false) === true)) {
                $previous = is_string($action['id'] ?? null) ? ($storedById[$action['id']] ?? null) : ($stored[$i] ?? null);

                if (is_array($previous) && ($previous['type'] ?? null) === WebhookAction::KEY && is_string($previous['url'] ?? null)) {
                    $action['url'] = $previous['url'];
                    $action['id'] ??= $previous['id'] ?? null;
                }
            }

            unset($action['keep_url']);

            if (! is_string($action['id'] ?? null) || preg_match(self::ID, $action['id']) !== 1) {
                $action['id'] = (string) Str::uuid7();
            }

            $incoming[$i] = $action;
        }

        return $incoming;
    }

    /**
     * Actions as shown in the API and the audit log: webhook URLs replaced
     * by `url_display` and `has_url`.
     *
     * @param  array<int, mixed>|null  $actions
     * @return array<int, mixed>
     */
    public static function shown(?array $actions): array
    {
        return array_map(function ($action) {
            if (! is_array($action) || ($action['type'] ?? null) !== WebhookAction::KEY) {
                return $action;
            }

            $url = is_string($action['url'] ?? null) ? $action['url'] : null;
            unset($action['url']);

            return [...$action, 'url_display' => $url === null ? null : WebhookAction::shown($url), 'has_url' => $url !== null];
        }, $actions ?? []);
    }
}

<?php

namespace App\Core\Branding;

use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\ConfigKinds;
use App\Core\Configuration\Models\ConfigDocument;

/**
 * BR-02, BR-03, BR-08: the `theme` configuration kind (docs/adr/010): one
 * document per scope (tenant, overridable per company or branch), key
 * `default`, with its own permissions `core.theme.view|edit|publish`.
 * Publishing is refused while ThemeSchema finds problems (WCAG AA
 * included). When read (resolved), the payload carries the signed URLs of
 * its assets under `asset_urls`.
 */
final class ThemeKind
{
    public const KEY = 'theme';

    public const SCOPES = [ConfigDocument::TENANT, ConfigDocument::COMPANY, ConfigDocument::BRANCH];

    public const DEFAULTS = ['preset' => 'light'];

    public static function register(ConfigKinds $kinds): void
    {
        $kinds->register(new ConfigKind(
            key: self::KEY,
            schema: fn (array $payload) => ThemeSchema::problems($payload),
            scopes: self::SCOPES,
            permissions: ['view' => 'core.theme.view', 'edit' => 'core.theme.edit', 'publish' => 'core.theme.publish'],
            merger: fn (array $payload) => [...$payload, 'asset_urls' => app(BrandAssets::class)->urlsFor($payload)],
            defaults: fn () => self::DEFAULTS,
            keys: [ConfigKind::DEFAULT_KEY],
            maxBytes: 16384,
        ));
    }

    /**
     * BR-08: the published theme at a place without a user (a till): the
     * branch's, else the company's, else the tenant's, else the default.
     *
     * @return array{payload: array, scope: ?array{type: string, id: ?string}, version: ?int}
     */
    public static function forPlace(string $companyId, ?string $branchId): array
    {
        $chain = array_values(array_filter([
            $branchId === null ? null : [ConfigDocument::BRANCH, $branchId],
            [ConfigDocument::COMPANY, $companyId],
            [ConfigDocument::TENANT, null],
        ]));

        $documents = ConfigDocument::query()
            ->with('published')
            ->where('kind', self::KEY)
            ->where('key', ConfigKind::DEFAULT_KEY)
            ->whereHas('published')
            ->where(function ($q) use ($chain) {
                foreach ($chain as [$type, $id]) {
                    $q->orWhere(fn ($c) => $c->where('scope_type', $type)->where('scope_id', $id));
                }
            })
            ->get()
            ->keyBy(fn (ConfigDocument $d) => $d->scope_type.':'.($d->scope_id ?? ''));

        foreach ($chain as [$type, $id]) {
            $document = $documents->get($type.':'.($id ?? ''));

            if ($document !== null) {
                return [
                    'payload' => $document->published->payload,
                    'scope' => ['type' => $type, 'id' => $id],
                    'version' => $document->published->version,
                ];
            }
        }

        return ['payload' => self::DEFAULTS, 'scope' => null, 'version' => null];
    }
}

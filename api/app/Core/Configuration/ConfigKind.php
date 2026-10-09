<?php

namespace App\Core\Configuration;

use App\Core\Configuration\Models\ConfigDocument;
use App\Core\Identity\Models\User;
use Closure;
use InvalidArgumentException;

/**
 * A kind of versioned configuration (LAY-06) a module registers with
 * ConfigKinds: theme, dashboard, navigation, form_layout, list_view,
 * pos_layout, template, ...
 *
 * - `schema` checks a payload before it is published: a PayloadSchema
 *   array, or a closure `fn (array $payload, string $key): list<problem>`
 *   (problems as PayloadSchema::problem() makes them; the document's key,
 *   for kinds whose catalogue differs per key, like form_layout);
 * - `scopes` are the scope types a document of this kind may have;
 * - `permissions` name the view, edit and publish permissions
 *   (`core.config.*` unless the kind brings its own, RBAC-01);
 * - `merger` (LAY-07) brings a stored payload up to date with the
 *   platform's current catalogue when it is read (see CatalogueMerge);
 *   it is called as `fn (array $payload, ConfigKind $kind, string $key)`;
 * - `layoutKeys` are the extra keys a stored entry may set over its
 *   catalogue entry, beyond CatalogueMerge::LAYOUT_KEYS (pass
 *   `$kind->layoutKeys()` to CatalogueMerge in the merger);
 * - `defaults` is the payload used when nothing is published anywhere
 *   along the chain, `fn (string $key): ?array` (null: the client keeps
 *   its built-in layout);
 * - `keys` limits the keys (a list, or a closure answering one), else
 *   any key matching KEY_PATTERN;
 * - `module` switches the kind off with its module (RBAC-08);
 * - `personal`: any signed-in user may keep, edit and publish documents of
 *   their own user scope (a personal list view or dashboard, LAY-01,
 *   LAY-04) without holding the kind's permissions; everything else still
 *   needs them (ConfigPolicy);
 * - `presenter` adapts a resolved payload to its reader, after the merger:
 *   `fn (array $payload, string $key, User $reader): array` (RBAC-05: drop
 *   columns their field rules hide, widgets whose data they can't read).
 */
final class ConfigKind
{
    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9_.\-]{0,99}$/';

    public const DEFAULT_KEY = 'default';

    public const DEFAULT_PERMISSIONS = [
        'view' => 'core.config.view',
        'edit' => 'core.config.edit',
        'publish' => 'core.config.publish',
    ];

    /** Largest payload stored, as JSON (bytes). */
    public const MAX_BYTES = 262144;

    /** @var array{view: string, edit: string, publish: string} */
    public readonly array $permissions;

    /**
     * @param  array<string, mixed>|Closure(array): list<array{path: string, code: string, message: string}>|null  $schema
     * @param  list<string>  $scopes
     * @param  array{view?: string, edit?: string, publish?: string}  $permissions
     * @param  (Closure(array, ConfigKind, string): array)|null  $merger
     * @param  (Closure(string): ?array)|null  $defaults
     * @param  list<string>|(Closure(): list<string>)|null  $keys
     * @param  list<string>  $layoutKeys
     */
    public function __construct(
        public readonly string $key,
        public readonly array|Closure|null $schema = null,
        public readonly array $scopes = [ConfigDocument::TENANT, ConfigDocument::COMPANY, ConfigDocument::BRANCH, ConfigDocument::LOCATION],
        array $permissions = [],
        public readonly ?Closure $merger = null,
        public readonly ?Closure $defaults = null,
        public readonly array|Closure|null $keys = null,
        public readonly string $module = 'core',
        public readonly int $maxBytes = self::MAX_BYTES,
        public readonly bool $personal = false,
        public readonly ?Closure $presenter = null,
        public readonly array $layoutKeys = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,59}$/', $key) !== 1) {
            throw new InvalidArgumentException("Invalid configuration kind [{$key}].");
        }

        if ($scopes === [] || array_diff($scopes, ConfigDocument::SCOPES) !== []) {
            throw new InvalidArgumentException("Configuration kind [{$key}] has unknown scope types.");
        }

        $unknown = array_diff(array_keys($permissions), array_keys(self::DEFAULT_PERMISSIONS));

        if ($unknown !== []) {
            throw new InvalidArgumentException("Configuration kind [{$key}] names unknown permission actions.");
        }

        $this->permissions = [...self::DEFAULT_PERMISSIONS, ...$permissions];
    }

    /** @param 'view'|'edit'|'publish' $action */
    public function permission(string $action): string
    {
        return $this->permissions[$action];
    }

    public function allows(string $scopeType): bool
    {
        return in_array($scopeType, $this->scopes, true);
    }

    public function acceptsKey(string $key): bool
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            return false;
        }

        $keys = $this->keys instanceof Closure ? ($this->keys)() : $this->keys;

        return $keys === null || in_array($key, $keys, true);
    }

    /**
     * What keeps $payload from being published (LAY-06).
     *
     * @return list<array{path: string, code: string, message: string}>
     */
    public function problems(array $payload, string $key = self::DEFAULT_KEY): array
    {
        return match (true) {
            $this->schema instanceof Closure => array_values(($this->schema)($payload, $key)),
            is_array($this->schema) => PayloadSchema::check($payload, $this->schema),
            default => [],
        };
    }

    /** LAY-07: the payload brought up to date with the current catalogue. */
    public function merge(array $payload, string $key = self::DEFAULT_KEY): array
    {
        return $this->merger === null ? $payload : ($this->merger)($payload, $this, $key);
    }

    /**
     * LAY-07: the keys a stored layout entry may set over its catalogue
     * entry: CatalogueMerge::LAYOUT_KEYS and the kind's own.
     *
     * @return list<string>
     */
    public function layoutKeys(): array
    {
        return array_values(array_unique([...CatalogueMerge::LAYOUT_KEYS, ...$this->layoutKeys]));
    }

    /** RBAC-05: the resolved payload as $reader may see it. */
    public function present(array $payload, string $key, User $reader): array
    {
        return $this->presenter === null ? $payload : ($this->presenter)($payload, $key, $reader);
    }

    public function defaultPayload(string $key = self::DEFAULT_KEY): ?array
    {
        $payload = $this->defaults === null ? null : ($this->defaults)($key);

        return $payload === null ? null : $this->merge($payload, $key);
    }
}

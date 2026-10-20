<?php

namespace App\Core\Webhooks\Catalogue;

use App\Core\Webhooks\Principals\WebhookPrincipal;
use Closure;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * HOOK-01: a public, versioned event type (`sale.completed.v1`), mapped from
 * one internal domain event and registered by the module that owns it
 * (WebhookCatalogue). Offered and sent only while `$module` is active.
 *
 * - `$capture(object $event): ?WebhookOccurrence` builds the occurrence from
 *   the event's own properties, with no queries (it runs in the request
 *   that raised the event, e.g. a POS upload); null when the event is not
 *   this type (an order event of another kind).
 * - `$resolve(WebhookOccurrence): ?WebhookOccurrence` (optional) runs in the
 *   fan-out job, in the tenant: it may read the module's own tables to add
 *   documented fields or the scope; null drops the event.
 * - `$permission` is what a principal must hold at the occurrence's scope;
 *   `$authorise(WebhookPrincipal, WebhookOccurrence): bool` (optional)
 *   replaces that check (a document's own visibility).
 * - `$hiddenBy` maps field rules (RBAC-05) to data keys:
 *   resource => field => list of data keys (dot paths) left out when the
 *   principal's rules hide that field.
 * - `$fields` documents the data keys (docs/webhooks.md, the catalogue API).
 */
final class WebhookEventType
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.v[1-9][0-9]*$/';

    /**
     * @param  class-string|null  $event
     * @param  list<string>  $fields
     * @param  array<string, array<string, list<string>>>  $hiddenBy
     */
    public function __construct(
        public readonly string $key,
        public readonly string $module,
        public readonly ?string $event,
        public readonly ?string $permission,
        public readonly ?Closure $capture = null,
        public readonly array $fields = [],
        public readonly array $hiddenBy = [],
        public readonly ?Closure $resolve = null,
        public readonly ?Closure $authorise = null,
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Invalid webhook event type [{$key}]: use noun.verb.vN.");
        }

        if ($permission === null && $authorise === null) {
            throw new InvalidArgumentException("Webhook event type [{$key}] needs a permission or an authorise check.");
        }
    }

    /** Whether $principal may receive $occurrence (RBAC-04: at the occurrence's scope). */
    public function allows(WebhookPrincipal $principal, WebhookOccurrence $occurrence): bool
    {
        if ($this->authorise !== null) {
            return (bool) ($this->authorise)($principal, $occurrence);
        }

        return $principal->can((string) $this->permission, $occurrence->scope());
    }

    /**
     * $data without the keys mapped to fields $principal's rules hide (RBAC-05).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function visibleData(WebhookPrincipal $principal, array $data): array
    {
        foreach ($this->hiddenBy as $resource => $fields) {
            $hidden = $principal->hiddenFields($resource);

            foreach ($fields as $field => $keys) {
                if (in_array($field, $hidden, true)) {
                    Arr::forget($data, $keys);
                }
            }
        }

        return $data;
    }
}

<?php

namespace App\Core\DocumentTemplates;

use App\Core\Configuration\ConfigKinds;
use App\Core\Configuration\Models\ConfigDocument;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Throwable;

/**
 * TPL-05: which template prints a document. The most specific published
 * `template` document for the type wins along branch → company → tenant
 * (drafts never apply); within it, the first variant whose `applies_when`
 * matches the document (customer tag, simple document conditions) wins,
 * else the template itself. With nothing published, the type's default
 * (DefaultTemplates) in the tenant's language.
 *
 * Variant conditions (all must hold):
 * - `customer_tags`: the customer has at least one of these tags (parties
 *   have tags; a "customer group" is a tag);
 * - `conditions`: `{field, op, value}` on a data path; ops eq, ne, gt,
 *   gte, lt, lte (numbers; money compared in major units, "5000.00"),
 *   contains (text or list), empty, not_empty.
 *
 * The till applies the same rule to the published payload it syncs
 * (pos/src/pos/templates/resolve.js).
 */
class TemplateResolver
{
    public const KIND = 'template';

    public function __construct(private readonly ConfigKinds $kinds) {}

    /**
     * The published payload (variants included) for $type at the place,
     * and where it came from; null payload when nothing is published.
     *
     * @return array{payload: ?array, document: ?ConfigDocument}
     */
    public function published(string $type, ?string $companyId, ?string $branchId): array
    {
        $chain = array_values(array_filter([
            $branchId === null ? null : [ConfigDocument::BRANCH, $branchId],
            $companyId === null ? null : [ConfigDocument::COMPANY, $companyId],
            [ConfigDocument::TENANT, null],
        ]));

        $documents = ConfigDocument::query()
            ->with('published')
            ->where('kind', self::KIND)
            ->where('key', $type)
            ->whereHas('published')
            ->where(function ($q) use ($chain) {
                foreach ($chain as [$scope, $id]) {
                    $q->orWhere(fn ($c) => $c->where('scope_type', $scope)->where('scope_id', $id));
                }
            })
            ->get()
            ->keyBy(fn (ConfigDocument $d) => $d->scope_type.':'.($d->scope_id ?? ''));

        foreach ($chain as [$scope, $id]) {
            $document = $documents->get($scope.':'.($id ?? ''));

            if ($document !== null) {
                return ['payload' => $document->published->payload, 'document' => $document];
            }
        }

        return ['payload' => null, 'document' => null];
    }

    /**
     * The template that prints $data: the published one (or the default)
     * with the first matching variant applied.
     *
     * @return array{template: array, source: ?array{document_id: string, scope: array{type: string, id: ?string}, version: int, variant: ?string}}
     */
    public function resolve(string $type, ?string $companyId, ?string $branchId, array $data): array
    {
        ['payload' => $payload, 'document' => $document] = $this->published($type, $companyId, $branchId);
        $payload ??= DefaultTemplates::for($type, $this->tenantLanguage());
        [$template, $variant] = self::applyVariant($payload, $data);

        return [
            'template' => $template,
            'source' => $document === null ? null : [
                'document_id' => $document->id,
                'scope' => ['type' => $document->scope_type, 'id' => $document->scope_id],
                'version' => $document->published->version,
                'variant' => $variant,
            ],
        ];
    }

    /** @return array{0: array, 1: ?string} the template to print and the id of the variant used */
    public static function applyVariant(array $payload, array $data): array
    {
        $base = $payload;
        unset($base['variants']);

        foreach ((array) ($payload['variants'] ?? []) as $variant) {
            if (is_array($variant) && self::matches((array) ($variant['applies_when'] ?? []), $data)) {
                foreach (['paper', 'margins', 'language', 'blocks'] as $key) {
                    if (array_key_exists($key, $variant)) {
                        $base[$key] = $variant[$key];
                    }
                }

                return [$base, is_string($variant['id'] ?? null) ? $variant['id'] : null];
            }
        }

        return [$base, null];
    }

    public static function matches(array $when, array $data): bool
    {
        $tags = array_values(array_filter((array) ($when['customer_tags'] ?? []), 'is_string'));

        if ($tags !== []) {
            $has = array_map('mb_strtolower', array_filter((array) ($data['customer']['tags'] ?? []), 'is_string'));

            if (array_intersect(array_map('mb_strtolower', $tags), $has) === []) {
                return false;
            }
        }

        foreach ((array) ($when['conditions'] ?? []) as $condition) {
            if (! is_array($condition) || ! self::holds($condition, $data)) {
                return false;
            }
        }

        return true;
    }

    private static function holds(array $condition, array $data): bool
    {
        $value = data_get($data, (string) ($condition['field'] ?? ''));
        $expected = $condition['value'] ?? null;
        $op = (string) ($condition['op'] ?? 'eq');

        if (is_array($value) && isset($value['minor'], $value['currency']) && is_string($value['currency'])) {
            $decimals = (int) ($data['currencies'][$value['currency']] ?? 2);
            $value = (string) BigDecimal::ofUnscaledValue((string) $value['minor'], $decimals);
        }

        $empty = $value === null || $value === '' || $value === [];

        return match ($op) {
            'empty' => $empty,
            'not_empty' => ! $empty,
            'contains' => is_array($value)
                ? in_array(mb_strtolower((string) $expected), array_map(fn ($v) => mb_strtolower((string) $v), array_filter($value, 'is_scalar')), true)
                : is_scalar($value) && $expected !== null && str_contains(mb_strtolower((string) $value), mb_strtolower((string) $expected)),
            'eq', 'ne' => (self::equal($value, $expected)) === ($op === 'eq'),
            'gt', 'gte', 'lt', 'lte' => self::compare($value, $expected, $op),
            default => false,
        };
    }

    private static function equal(mixed $value, mixed $expected): bool
    {
        $a = self::number($value);
        $b = self::number($expected);

        if ($a !== null && $b !== null) {
            return $a->isEqualTo($b);
        }

        return is_scalar($value) && is_scalar($expected) && mb_strtolower((string) $value) === mb_strtolower((string) $expected);
    }

    private static function compare(mixed $value, mixed $expected, string $op): bool
    {
        $a = self::number($value);
        $b = self::number($expected);

        if ($a === null || $b === null) {
            return false;
        }

        $cmp = $a->compareTo($b);

        return match ($op) {
            'gt' => $cmp > 0,
            'gte' => $cmp >= 0,
            'lt' => $cmp < 0,
            default => $cmp <= 0,
        };
    }

    private static function number(mixed $value): ?BigDecimal
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1)) {
            return null;
        }

        try {
            return BigDecimal::of((string) $value)->toScale(8, RoundingMode::Down);
        } catch (Throwable) {
            return null;
        }
    }

    private function tenantLanguage(): string
    {
        $tenantId = app(TenantContext::class)->id();

        return $tenantId === null ? 'en' : (string) (Tenant::query()->whereKey($tenantId)->value('default_locale') ?? 'en');
    }
}

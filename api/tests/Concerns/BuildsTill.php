<?php

namespace Tests\Concerns;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\MasterData\Taxes\TaxCategoryCode;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\DeviceSecrets;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Illuminate\Testing\TestResponse;

/**
 * NFR-04, AUTH-06..AUTH-08: a paired till and the `pos` module's till
 * permissions (the POS module registers the real ones; the names match the
 * system role templates), master data for it, and helpers to pull.
 */
trait BuildsTill
{
    use BuildsOrganisation, RegistersTillModule;

    /** Activate the module for $tenantId: system roles pick up its permissions. */
    protected function activateTill(string $tenantId): void
    {
        $this->asTenant($tenantId, fn () => app(ModuleRegistry::class)->activate('pos'));
    }

    /**
     * A device at $location, paired through the API by the owner.
     *
     * @return array{id: string, token: string, secret: string, kid: string}
     */
    protected function pairTill(Location $location, string $name = 'Till'): array
    {
        $id = $this->postJson("/api/v1/locations/{$location->id}/devices", ['name' => $name], $this->headersFor())->assertCreated()->json('data.id');
        $code = $this->postJson("/api/v1/devices/{$id}/pairing-code", [], $this->headersFor())->assertOk()->json('code');
        $paired = $this->postJson('/api/v1/devices/pair', ['code' => $code, 'device_name' => $name])->assertOk();

        return ['id' => $id, 'token' => $paired->json('token'), 'secret' => $paired->json('device_secret'), 'kid' => $paired->json('device_secret_kid')];
    }

    /** HMAC-SHA256 of $message under a base64url secret, base64url: what the app sends as a proof. */
    protected function proof(string $secret, string $message): string
    {
        return DeviceSecrets::encode(hash_hmac('sha256', $message, DeviceSecrets::decode($secret), true));
    }

    /**
     * Rotate $till's secret the way the app does (challenge, rotate, activate).
     *
     * @return array{id: string, token: string, secret: string, kid: string}
     */
    protected function rotateTill(array $till): array
    {
        $nonce = $this->getJson('/api/v1/sync/device-secret/challenge', $this->deviceHeaders($till))->assertOk()->json('nonce');
        $pending = $this->postJson('/api/v1/sync/device-secret/rotate', [
            'kid' => $till['kid'], 'nonce' => $nonce, 'proof' => $this->proof($till['secret'], "rotate:v1\n{$till['id']}\n{$nonce}"),
        ], $this->deviceHeaders($till))->assertOk()->assertJsonPath('status', 'pending');
        $kid = $pending->json('kid');
        $this->postJson('/api/v1/sync/device-secret/activate', [
            'kid' => $kid, 'proof' => $this->proof($pending->json('device_secret'), "activate:v1\n{$till['id']}\n{$kid}"),
        ], $this->deviceHeaders($till))->assertOk()->assertJsonPath('status', 'current');

        return [...$till, 'secret' => $pending->json('device_secret'), 'kid' => $kid];
    }

    protected function deviceHeaders(array $till): array
    {
        return ['Authorization' => 'Bearer '.$till['token'], 'Accept' => 'application/json'];
    }

    /** @param array<string, string> $cursors */
    protected function pull(array $till, array $entities, array $cursors = [], ?int $limit = null): TestResponse
    {
        $query = http_build_query(array_filter(['entities' => $entities, 'cursors' => $cursors, 'limit' => $limit], fn ($v) => $v !== [] && $v !== null));

        return $this->getJson('/api/v1/sync/pull?'.$query, $this->deviceHeaders($till));
    }

    /**
     * Pull $entity page by page from $cursor until nothing is left.
     *
     * @return array{upserts: array<string, array>, tombstones: list<string>, cursor: string, pages: int, seen: list<string>}
     */
    protected function pullAll(array $till, string $entity, ?string $cursor = null, int $limit = 500): array
    {
        $upserts = [];
        $tombstones = [];
        $seen = [];
        $pages = 0;

        do {
            $page = $this->pull($till, [$entity], $cursor === null ? [] : [$entity => $cursor], $limit)->assertOk()->json("entities.{$entity}");
            $pages++;

            foreach ($page['upserts'] as $row) {
                $upserts[$row['id']] = $row;
                $seen[] = $row['id'];
                $tombstones = array_values(array_diff($tombstones, [$row['id']]));
            }

            foreach ($page['tombstones'] as $id) {
                unset($upserts[$id]);
                $tombstones[] = $id;
                $seen[] = $id;
            }

            $cursor = $page['cursor'];
        } while ($page['has_more'] && $pages < 1000);

        return ['upserts' => $upserts, 'tombstones' => $tombstones, 'cursor' => $cursor, 'pages' => $pages, 'seen' => $seen];
    }

    /**
     * A VAT code for $company with $rate (null: rate needed) since 2026-01-01,
     * and a tax category (shared unless $own) defaulting to it.
     *
     * @return array{code: TaxCode, category: TaxCategory}
     */
    protected function taxes(Company $company, ?string $rate = '16', string $code = 'VAT', bool $own = false): array
    {
        return $this->inTenant(function () use ($company, $rate, $code, $own) {
            $taxCode = TaxCode::create(['company_id' => $company->id, 'code' => $code, 'name' => "Tax {$code}", 'kind' => 'vat']);
            TaxRate::create([
                'tax_code_id' => $taxCode->id,
                'rate' => $rate === null ? null : TaxCode::normaliseRate($rate),
                'effective_from' => '2026-01-01',
                'needs_confirmation' => $rate === null,
            ]);
            $category = TaxCategory::create(['name' => "Goods {$code}", 'company_id' => $own ? $company->id : null]);
            TaxCategoryCode::create(['tax_category_id' => $category->id, 'company_id' => $company->id, 'tax_code_id' => $taxCode->id]);

            return ['code' => $taxCode, 'category' => $category];
        });
    }

    protected function uom(string $code = 'EA'): Uom
    {
        return $this->inTenant(fn () => Uom::query()->where('code', $code)->first() ?? Uom::create(['code' => $code, 'name' => $code, 'kind' => 'count']));
    }

    protected function makeItem(string $code, ?Company $company = null, ?string $taxCategoryId = null, array $extra = []): Item
    {
        return $this->inTenant(fn () => Item::create([
            'code' => $code,
            'name' => "Item {$code}",
            'type' => 'stock',
            'base_uom_id' => $this->uom()->id,
            'company_id' => $company?->id,
            'tax_category_id' => $taxCategoryId,
            ...$extra,
        ]));
    }
}

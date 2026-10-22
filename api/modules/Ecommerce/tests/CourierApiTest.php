<?php

namespace Modules\Ecommerce\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Identity\Services\Authenticate;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Modules\Ecommerce\Courier\CourierDeliveries;
use Modules\Ecommerce\Events\OrderEvent;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\DeliveryUpdate;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Tests\Concerns\BuildsEcommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MKT-04, API-01..API-03, ECOM-27, ECOM-28 (ADR 020, ADR 024): the courier's
// public API. Orders waiting for a courier and those handed to it (the
// customer's contact only once handed, field rules applied, never a rider's
// position); booking with tracking and status updates through the delivery
// services, idempotent by the client's reference and audited as the
// principal; scopes, other tenants, API keys and app tokens, rate limits;
// and the `order.ready_for_delivery.v1` webhook event.
class CourierApiTest extends TestCase
{
    use BuildsEcommerce, BuildsMarketplace, RefreshTenantDatabase;

    private const SCOPES = ['ecommerce.order.read', 'ecommerce.delivery.write'];

    private Role $courierRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEcommerce();
        $this->courierRole = $this->inTenant(fn () => $this->role('Courier', ['ecommerce.order.view', 'ecommerce.delivery.assign', 'ecommerce.delivery.deliver']));
    }

    /** An accepted cash on delivery order of a widget: its delivery waits for a rider or courier. */
    private function waiting(): Delivery
    {
        $id = $this->orderId($this->placeOrder($this->cart([[$this->widgetProduct, '1']]))->assertCreated()->json('data.number'));
        $this->api('POST', "ecommerce/orders/{$id}/accept", [], $this->manager)->assertOk();

        return $this->inTenant(fn () => Delivery::query()->where('order_id', $id)->sole());
    }

    /** @return array<string, string> bearer headers of a new API key (the Courier role at tenant scope by default) */
    private function key(array $scopes = self::SCOPES, ?string $roleId = null, ?User $by = null): array
    {
        $secret = $this->postJson('/api/v1/api-keys', ['name' => 'Courier bridge', 'role_id' => $roleId ?? $this->courierRole->id, 'scope_type' => 'tenant', 'scopes' => $scopes],
            $this->headersFor($by))->assertCreated()->json('secret');

        return ['Authorization' => 'Bearer '.$secret, 'Accept' => 'application/json'];
    }

    /** @return array<string, string> bearer headers of an app installed with the Courier role (an app principal) */
    private function appToken(array $scopes = self::SCOPES): array
    {
        // OAuth codes and tokens are timed by the real clock (Passport): the test goes on in real time.
        CarbonImmutable::setTestNow();
        $app = $this->listedApp(['name' => 'Courier app', 'scopes' => $scopes]);
        $pkce = $this->pkce();
        $approved = $this->approve($app['client_id'], $pkce['challenge'], $this->headersFor(), ['role' => $this->courierRole->id, 'scope_type' => 'tenant', 'scope_id' => null]);
        $this->assertSame(200, $approved->getStatusCode(), (string) $approved->getContent());
        $exchanged = $this->exchange(self::codeFrom($approved->json('data.redirect_to')), $pkce['verifier'], $app);
        $this->assertSame(200, $exchanged->getStatusCode(), (string) $exchanged->getContent());
        $token = $exchanged->json('access_token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function book(Delivery $delivery, array $headers, array $body = []): TestResponse
    {
        return $this->postJson("/api/v1/ecommerce/courier/deliveries/{$delivery->id}/booking", [...['reference' => 'bk-1', 'tracking_reference' => 'TRK-001', 'tracking_url' => 'https://track.example.com/TRK-001'], ...$body], $headers);
    }

    private function report(Delivery $delivery, array $headers, array $body): TestResponse
    {
        return $this->postJson("/api/v1/ecommerce/courier/deliveries/{$delivery->id}/status", [...['occurred_at' => CarbonImmutable::now()->toIso8601String()], ...$body], $headers);
    }

    public function test_an_api_key_reads_waiting_orders_without_the_customers_contact_or_any_position(): void
    {
        $delivery = $this->waiting();
        $this->inTenant(fn () => Delivery::query()->whereKey($delivery->id)->update(['last_latitude' => '-1.263300', 'last_longitude' => '36.804000', 'located_at' => now()]));
        $headers = $this->key();

        $list = $this->getJson('/api/v1/ecommerce/courier/orders', $headers)->assertOk()->assertHeader('RateLimit-Limit');
        $this->assertSame([$delivery->order_id], array_column($list->json('data'), 'id'));
        $row = $list->json('data.0');
        $this->assertFalse($row['handed_to_you']);
        $this->assertSame([null, null, null], [$row['contact_name'], $row['contact_phone'], $row['address']]);
        $this->assertSame(['Westlands', 'Widget', '136000'], [$row['zone']['name'], $row['lines'][0]['name'], $row['collect_minor']]);
        $this->assertSame(['data', 'links', 'meta'], array_keys($list->json()));

        $show = $this->getJson("/api/v1/ecommerce/courier/orders/{$delivery->order_id}", $headers)->assertOk();
        foreach ([$list, $show] as $response) {
            foreach (['latitude', 'longitude', 'trail', 'last_location', '-1.2633', '36.804', 'Wanjiku', '+254712345678'] as $never) {
                $this->assertStringNotContainsString($never, (string) $response->getContent());
            }
        }

        // Nothing is handed to the key yet.
        $this->assertSame([], $this->getJson('/api/v1/ecommerce/courier/orders?status=handed', $headers)->assertOk()->json('data'));
    }

    public function test_an_app_books_a_delivery_and_reports_it_idempotently_audited_as_its_principal(): void
    {
        $delivery = $this->waiting();
        $headers = $this->appToken();

        $booked = $this->book($delivery, $headers)->assertCreated();
        $this->assertTrue($booked->json('data.handed_to_you'));
        $this->assertSame(['Wanjiku Kamau', '+254712345678', 'TRK-001', 'assigned'], [$booked->json('data.contact_name'), $booked->json('data.contact_phone'), $booked->json('data.delivery.tracking_reference'), $booked->json('data.delivery.status')]);

        $principal = $this->inTenant(fn () => User::query()->where('status', User::STATUS_SERVICE)->sole());
        $rider = $this->inTenant(fn () => Rider::query()->where('user_id', $principal->id)->sole());
        $this->assertSame([$rider->id, Delivery::ASSIGNED], $this->inTenant(fn () => [$delivery->refresh()->rider_id, $delivery->status]));

        // Booking again with the same reference answers the same; other tracking with it is refused.
        $this->book($delivery, $headers)->assertOk()->assertJsonPath('data.delivery.tracking_reference', 'TRK-001');
        $this->book($delivery, $headers, ['tracking_reference' => 'TRK-999'])->assertStatus(422)->assertJsonPath('code', 'reference_reused');
        // A new reference updates the tracking of a delivery the courier holds.
        $this->book($delivery, $headers, ['reference' => 'bk-2', 'tracking_reference' => 'TRK-002'])->assertOk()->assertJsonPath('data.delivery.tracking_reference', 'TRK-002');

        // Picked up, sent twice: one rider update, the order goes out.
        $this->report($delivery, $headers, ['reference' => 'st-1', 'status' => 'picked_up', 'occurred_at' => '2026-11-30T08:40:00Z'])->assertOk()->assertJsonPath('data.delivery.status', 'picked_up');
        $this->report($delivery, $headers, ['reference' => 'st-1', 'status' => 'picked_up', 'occurred_at' => '2026-11-30T08:40:00Z'])->assertOk();
        $this->report($delivery, $headers, ['reference' => 'st-1', 'status' => 'failed', 'reason' => 'Gate closed', 'occurred_at' => '2026-11-30T08:40:00Z'])->assertStatus(422)->assertJsonPath('code', 'payload_mismatch');
        $this->assertSame(1, $this->inTenant(fn () => DeliveryUpdate::query()->where('id', CourierDeliveries::updateId($principal, 'st-1'))->count()));
        $this->assertSame(Order::OUT_FOR_DELIVERY, $this->inTenant(fn () => Order::query()->findOrFail($delivery->order_id)->status));

        // Delivered: the customer's code is the proof.
        $code = $this->sentDeliveryCode();
        $this->report($delivery, $headers, ['reference' => 'st-2', 'status' => 'delivered'])->assertStatus(422)->assertJsonPath('code', 'proof_required');
        $this->report($delivery, $headers, ['reference' => 'st-3', 'status' => 'delivered', 'code' => $code])->assertOk()->assertJsonPath('data.delivery.status', 'delivered');
        $this->assertSame(Order::DELIVERED, $this->inTenant(fn () => Order::query()->findOrFail($delivery->order_id)->status));

        // Audited as the app's principal, with its key and install in the metadata (ADR 020, ADR 024).
        $this->inTenant(function () use ($principal, $delivery) {
            $entries = AuditEntry::query()->where('user_id', $principal->id)->where('auditable_id', $delivery->id)->get();
            $this->assertNotEmpty($entries);
            $metadata = $entries->first()->after['metadata'] ?? [];
            $this->assertNotNull($metadata['api_key_id'] ?? null);
            $this->assertNotNull($metadata['app_installation_id'] ?? null);
            foreach ($entries as $entry) {
                $this->assertArrayNotHasKey('last_latitude', $entry->after ?? []);
            }
        });
    }

    public function test_a_failed_delivery_needs_a_reason_and_goes_back_to_waiting(): void
    {
        $delivery = $this->waiting();
        $headers = $this->key();
        $this->book($delivery, $headers)->assertCreated();

        $this->report($delivery, $headers, ['reference' => 'f-1', 'status' => 'failed'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->report($delivery, $headers, ['reference' => 'f-1', 'status' => 'failed', 'reason' => 'No driver free'])->assertOk()
            ->assertJsonPath('data.handed_to_you', false)->assertJsonPath('data.contact_phone', null);
        $this->inTenant(fn () => $this->assertSame([Delivery::UNASSIGNED, null, 'No driver free'], [$delivery->refresh()->status, $delivery->rider_id, $delivery->reason]));
        // Sent again: the same answer, nothing more happens.
        $this->report($delivery, $headers, ['reference' => 'f-1', 'status' => 'failed', 'reason' => 'No driver free'])->assertOk();
        // No longer the courier's: a new update is refused.
        $this->report($delivery, $headers, ['reference' => 'f-2', 'status' => 'picked_up'])->assertNotFound()->assertJsonPath('code', 'delivery_unknown');
    }

    public function test_a_delivery_held_by_a_rider_cannot_be_booked(): void
    {
        $delivery = $this->waiting();
        $this->api('POST', "ecommerce/deliveries/{$delivery->id}/assign", ['rider_id' => $this->rider->id], $this->manager)->assertOk();

        $this->book($delivery, $this->key())->assertStatus(409)->assertJsonPath('code', 'delivery_not_assignable');
        $this->inTenant(fn () => $this->assertSame($this->rider->id, $delivery->refresh()->rider_id));
    }

    public function test_scopes_are_required_for_reads_and_writes(): void
    {
        $delivery = $this->waiting();

        $readOnly = $this->key(['ecommerce.order.read']);
        $this->getJson('/api/v1/ecommerce/courier/orders', $readOnly)->assertOk();
        $this->book($delivery, $readOnly)->assertForbidden()->assertJsonPath('code', 'api_scope_missing')->assertJsonPath('scope', 'ecommerce.delivery.write');

        $writeOnly = $this->key(['ecommerce.delivery.write']);
        $this->getJson('/api/v1/ecommerce/courier/orders', $writeOnly)->assertForbidden()->assertJsonPath('code', 'api_scope_missing')->assertJsonPath('scope', 'ecommerce.order.read');
        $this->getJson("/api/v1/ecommerce/courier/orders/{$delivery->order_id}", $writeOnly)->assertForbidden()->assertJsonPath('code', 'api_scope_missing');

        // An app consented without the delivery scope cannot book.
        $this->book($delivery, $this->appToken(['ecommerce.order.read']))->assertForbidden()->assertJsonPath('code', 'api_scope_missing');

        // A role without the permissions: nothing.
        $auditorKey = $this->key(self::SCOPES, $this->roles->get('read_only_auditor')->id);
        $this->book($delivery, $auditorKey)->assertForbidden();
    }

    public function test_another_tenants_orders_and_deliveries_are_not_found(): void
    {
        $delivery = $this->waiting();
        $other = $this->otherTenant();
        [$token, $roleId] = $this->asTenant($other['user']->tenant_id, function () use ($other) {
            app(ModuleRegistry::class)->activate('ecommerce');

            return [app(Authenticate::class)->issueToken($other['user'], '127.0.0.1', 'tests'),
                $this->role('Courier', ['ecommerce.order.view', 'ecommerce.delivery.assign', 'ecommerce.delivery.deliver'])->id];
        });
        $secret = $this->postJson('/api/v1/api-keys', ['name' => 'Other courier', 'role_id' => $roleId, 'scope_type' => 'tenant', 'scopes' => self::SCOPES], $this->bearer($token))->assertCreated()->json('secret');
        $headers = ['Authorization' => 'Bearer '.$secret, 'Accept' => 'application/json'];

        $this->assertSame([], $this->getJson('/api/v1/ecommerce/courier/orders?status=all', $headers)->assertOk()->json('data'));
        $this->getJson("/api/v1/ecommerce/courier/orders/{$delivery->order_id}", $headers)->assertNotFound();
        $this->book($delivery, $headers)->assertNotFound();
        $this->report($delivery, $headers, ['reference' => 'x-1', 'status' => 'picked_up'])->assertNotFound();
        $this->inTenant(fn () => $this->assertSame([Delivery::UNASSIGNED, null], [$delivery->refresh()->status, $delivery->rider_id]));
    }

    public function test_field_rules_hide_the_customers_contact_from_the_courier(): void
    {
        $delivery = $this->waiting();
        $this->inTenant(fn () => FieldRule::create(['role_id' => $this->courierRole->id, 'resource' => 'ecom_order', 'field' => 'contact_phone', 'mode' => 'hidden']));
        $headers = $this->key();

        $booked = $this->book($delivery, $headers)->assertCreated();
        $this->assertSame('Wanjiku Kamau', $booked->json('data.contact_name'));
        $this->assertArrayNotHasKey('contact_phone', $booked->json('data'));
        $this->assertArrayNotHasKey('contact_phone', $this->getJson("/api/v1/ecommerce/courier/orders/{$delivery->order_id}", $headers)->assertOk()->json('data'));
    }

    public function test_reads_and_writes_are_rate_limited_per_caller(): void
    {
        config(['ecommerce.courier.reads_per_minute' => 2, 'ecommerce.courier.writes_per_minute' => 1]);
        $delivery = $this->waiting();
        $headers = $this->key();

        $this->getJson('/api/v1/ecommerce/courier/orders', $headers)->assertOk();
        $this->getJson('/api/v1/ecommerce/courier/orders', $headers)->assertOk();
        $this->getJson('/api/v1/ecommerce/courier/orders', $headers)->assertStatus(429);
        // Another caller has its own window.
        $this->getJson('/api/v1/ecommerce/courier/orders', $this->key())->assertOk();

        $this->book($delivery, $headers)->assertCreated();
        $this->book($delivery, $headers)->assertStatus(429);
    }

    public function test_people_use_the_same_routes_within_their_role(): void
    {
        $delivery = $this->waiting();

        // The branch manager reads (no contact: not handed to them); the rider template has no order view.
        $this->getJson('/api/v1/ecommerce/courier/orders', $this->headersFor($this->manager))->assertOk()->assertJsonPath('data.0.contact_phone', null);
        $this->getJson('/api/v1/ecommerce/courier/orders', $this->headersFor($this->riderUser))->assertForbidden();
        // A manager may book (assign), but not report as a courier (no deliver).
        $this->book($delivery, $this->headersFor($this->manager))->assertCreated()->assertJsonPath('data.handed_to_you', true);
        $this->report($delivery, $this->headersFor($this->manager), ['reference' => 'm-1', 'status' => 'picked_up'])->assertForbidden();
    }

    public function test_the_ready_for_delivery_event_is_sent_only_for_a_delivery_waiting_for_a_courier(): void
    {
        $delivery = $this->waiting();
        $type = app(WebhookCatalogue::class)->find('order.ready_for_delivery.v1');
        $this->assertNotNull($type);
        $order = $this->inTenant(fn () => Order::query()->findOrFail($delivery->order_id));
        $event = fn (string $to) => new OrderEvent($order->tenant_id, OrderEvent::STATUS_CHANGED, $order->id, $order->company_id, $order->number, $order->currency, (string) $order->total_minor, 'picking', $to);

        $this->assertNull(($type->capture)($event('cancelled')));
        $occurrence = ($type->capture)($event(Order::READY));
        $this->assertSame(['order_id', 'number', 'company_id'], array_keys($occurrence->data));

        $resolved = $this->inTenant(fn () => ($type->resolve)($occurrence));
        $this->assertSame(['order_id' => $order->id, 'number' => $order->number, 'company_id' => $order->company_id, 'location_id' => $order->location_id, 'delivery_id' => $delivery->id], $resolved->data);
        $this->assertSame($type->fields, array_keys($resolved->data));

        // Handed to a rider already: dropped.
        $this->api('POST', "ecommerce/deliveries/{$delivery->id}/assign", ['rider_id' => $this->rider->id], $this->manager)->assertOk();
        $this->assertNull($this->inTenant(fn () => ($type->resolve)($occurrence)));
    }
}

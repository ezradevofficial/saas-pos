<?php

namespace Modules\Ecommerce\Tests;

use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\DeliveryUpdate;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Tests\Concerns\BuildsEcommerce;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\UsesObjectStorage;
use Tests\TestCase;

// ECOM-26 to ECOM-30, NFR-04: riders, their own deliveries, status and
// location updates uploaded from the phone (idempotent by id, also after
// being queued offline), proof by delivery code or photo, cash on delivery.
class RiderTest extends TestCase
{
    use BuildsEcommerce, RefreshTenantDatabase, UsesObjectStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEcommerce();
    }

    public function test_a_rider_sees_only_their_own_deliveries(): void
    {
        $delivery = $this->assigned();
        $other = $this->userWith('rider', Scope::branch($this->branchA->id));
        $this->inTenant(fn () => Rider::create(['company_id' => $this->acme->id, 'user_id' => $other->id, 'name' => 'Other Rider', 'active' => true]));

        $mine = $this->api('GET', 'ecommerce/rider/deliveries', [], $this->riderUser)->assertOk()->json('data');
        $this->assertSame([$delivery->id], array_column($mine, 'id'));
        $this->assertSame(['Wanjiku', '+254712345678', '136000'], [$mine[0]['customer_name'], $mine[0]['customer_phone'], $mine[0]['collect_minor']]);
        $this->assertSame([], $this->api('GET', 'ecommerce/rider/deliveries', [], $other)->json('data'));

        // Another rider's delivery is unknown to them.
        $result = $this->updates([$this->update($delivery, 'status', ['status' => 'picked_up'])], $other)->assertStatus(422)->json('results.0');
        $this->assertSame(['rejected', 'delivery_unknown'], [$result['status'], $result['error']['code']]);

        // Without the permission (a cashier): refused.
        $this->api('GET', 'ecommerce/rider/deliveries', [], $this->userWith('cashier', Scope::branch($this->branchA->id)))->assertForbidden();
    }

    public function test_offline_updates_upload_once_and_deliver_with_the_customers_code(): void
    {
        $delivery = $this->assigned();
        $picked = $this->update($delivery, 'status', ['status' => 'picked_up', 'occurred_at' => '2026-11-30T08:40:00Z']);
        $where = $this->update($delivery, 'location', ['latitude' => '-1.2633', 'longitude' => '36.8040', 'occurred_at' => '2026-11-30T08:45:00Z']);

        // Queued while offline, sent twice (the first answer was lost): stored once.
        $this->updates([$picked, $where])->assertOk();
        $this->updates([$picked, $where])->assertOk()->assertJsonPath('results.0.status', 'stored')->assertJsonPath('results.1.status', 'stored');
        // The location point leaves no row (review M5): its id is remembered in the cache.
        $this->assertSame(1, $this->inTenant(fn () => DeliveryUpdate::query()->count()));
        $order = $this->inTenant(fn () => Order::query()->findOrFail($delivery->order_id));
        $this->assertSame(Order::OUT_FOR_DELIVERY, $order->status);

        // A resend with other content is refused.
        $this->updates([[...$picked, 'note' => 'changed']])->assertStatus(422)->assertJsonPath('results.0.error.code', 'payload_mismatch');

        // The customer got the delivery code with the out-for-delivery message (a secret, never stored).
        $code = $this->sentDeliveryCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->updates([$this->update($delivery, 'status', ['status' => 'delivered', 'code' => $wrong])])->assertStatus(422)->assertJsonPath('results.0.error.code', 'code_invalid');
        $this->updates([$this->update($delivery, 'status', ['status' => 'delivered'])])->assertStatus(422)->assertJsonPath('results.0.error.code', 'proof_required');
        $this->updates([$this->update($delivery, 'status', ['status' => 'delivered', 'code' => $code, 'collected_minor' => '130000'])])->assertOk();

        $delivery->refresh();
        $order->refresh();
        $this->assertSame([Delivery::DELIVERED, 'code', 130000], [$delivery->status, $delivery->proof_kind, $delivery->cod_collected_minor]);
        $this->assertSame(['cod_short'], array_column($delivery->flags, 'code'));
        $this->assertSame([Order::DELIVERED, 'cod_collected'], [$order->status, $order->payment_status]);
        // Review M3: delivered, the position is cleared; when the rider last reported stays.
        $this->assertSame([null, '2026-11-30T08:45:00+00:00'], [$delivery->last_latitude, $delivery->located_at?->toIso8601String()]);

        // The tracking page shows the rider's first name and when they last reported.
        $this->assertSame('Juma', $this->shopCall('GET', "duka/orders/{$order->number}?t={$order->tracking_token_secret}")->json('data.rider.first_name'));
    }

    public function test_five_wrong_codes_lock_the_delivery(): void
    {
        $delivery = $this->assigned();
        $this->updates([$this->update($delivery, 'status', ['status' => 'picked_up'])])->assertOk();
        $wrong = $this->sentDeliveryCode() === '999999' ? '888888' : '999999';

        for ($i = 0; $i < 5; $i++) {
            $this->updates([$this->update($delivery, 'status', ['status' => 'delivered', 'code' => $wrong])])->assertJsonPath('results.0.error.code', 'code_invalid');
        }

        $this->updates([$this->update($delivery, 'status', ['status' => 'delivered', 'code' => $this->sentDeliveryCode()])])->assertJsonPath('results.0.error.code', 'code_locked');
    }

    public function test_a_locked_delivery_refuses_every_delivered_update_even_with_a_photo(): void
    {
        Storage::fake('media');
        $delivery = $this->assigned();
        $this->updates([$this->update($delivery, 'status', ['status' => 'picked_up'])])->assertOk();
        $wrong = $this->sentDeliveryCode() === '999999' ? '888888' : '999999';

        // Five wrong codes in one upload: counted under the delivery's lock, then locked.
        $this->updates(array_map(fn () => $this->update($delivery, 'status', ['status' => 'delivered', 'code' => $wrong]), range(1, 6)))
            ->assertJsonPath('results.4.error.code', 'code_invalid')->assertJsonPath('results.5.error.code', 'code_locked');
        $this->assertSame([5, true], [$delivery->refresh()->code_attempts, $delivery->code_locked]);

        // A photo does not get round the lock.
        $this->json('POST', "/api/v1/ecommerce/rider/deliveries/{$delivery->id}/photo", ['photo' => UploadedFile::fake()->image('door.jpg')], $this->headersFor($this->riderUser))->assertCreated();
        $this->updates([$this->update($delivery, 'status', ['status' => 'delivered'])])->assertJsonPath('results.0.error.code', 'code_locked');
        $this->assertSame(Delivery::PICKED_UP, $delivery->refresh()->status);
    }

    public function test_cash_confirmed_by_staff_is_the_staff_members_not_the_riders(): void
    {
        $delivery = $this->assigned();
        $this->updates([$this->update($delivery, 'status', ['status' => 'picked_up'])])->assertOk();
        $this->api('POST', "ecommerce/orders/{$delivery->order_id}/deliver", [], $this->manager)->assertOk();

        $delivery->refresh();
        $this->assertSame($this->manager->id, $delivery->collected_by);
        $this->assertSame([], $this->api('GET', 'ecommerce/cashups/pending', [], $this->manager)->assertOk()->json('data'), 'nothing for the rider to hand in');

        // A rider's own delivery is theirs to cash up.
        $second = $this->assigned();
        $this->updates([$this->update($second, 'status', ['status' => 'picked_up'])])->assertOk();
        $this->updates([$this->update($second, 'status', ['status' => 'delivered', 'code' => $this->sentDeliveryCode()])])->assertOk();
        $this->assertSame($this->riderUser->id, $second->refresh()->collected_by);
        $this->assertSame('136000', $this->api('GET', 'ecommerce/cashups/pending', [], $this->manager)->assertOk()->json('data.0.expected_minor'));
    }

    public function test_a_photo_proves_delivery_and_a_failed_delivery_returns_the_order(): void
    {
        Storage::fake('media');
        $first = $this->assigned();
        $this->updates([$this->update($first, 'status', ['status' => 'picked_up'])])->assertOk();
        $this->json('POST', "/api/v1/ecommerce/rider/deliveries/{$first->id}/photo", ['photo' => UploadedFile::fake()->image('door.jpg')], $this->headersFor($this->riderUser))->assertCreated();
        $this->updates([$this->update($first, 'status', ['status' => 'delivered'])])->assertOk();
        $this->assertSame('photo', $first->refresh()->proof_kind);

        // Failed before pick-up: back to the board, unassigned.
        $second = $this->assigned();
        $this->updates([$this->update($second, 'status', ['status' => 'failed', 'note' => 'Bike broke down'])])->assertOk();
        $this->assertSame([Delivery::UNASSIGNED, null], [$second->refresh()->status, $second->rider_id]);

        // Failed after pick-up: the order returns to its location.
        $this->api('POST', "ecommerce/deliveries/{$second->id}/assign", ['rider_id' => $this->rider->id], $this->manager)->assertOk();
        $this->updates([$this->update($second, 'status', ['status' => 'picked_up'])])->assertOk();
        $this->updates([$this->update($second, 'status', ['status' => 'failed', 'note' => 'Nobody home'])])->assertOk();
        $this->assertSame(Order::RETURNED, $this->inTenant(fn () => Order::query()->findOrFail($second->order_id)->status));
        $this->assertSame(Delivery::FAILED, $second->refresh()->status);
    }

    /** NFR-07: a proof photo goes to the object store under the tenant, never to the server's own disk. */
    public function test_a_proof_photo_goes_to_the_object_store(): void
    {
        $this->useObjectStorage();
        $delivery = $this->assigned();
        $this->updates([$this->update($delivery, 'status', ['status' => 'picked_up'])])->assertOk();
        $this->json('POST', "/api/v1/ecommerce/rider/deliveries/{$delivery->id}/photo", ['photo' => UploadedFile::fake()->image('door.jpg')], $this->headersFor($this->riderUser))->assertCreated();

        $this->assertOnObjectStorage('s3', $delivery->refresh()->proof_photo_path, $delivery->tenant_id, "ecommerce/proofs/{$delivery->id}");
    }

    public function test_riders_are_users_of_the_company_and_managed_with_the_permission(): void
    {
        $user = $this->inTenant(fn () => $this->colleague($this->owner, ['name' => 'Baraka Mwangi']));
        $created = $this->api('POST', 'ecommerce/riders', ['company_id' => $this->acme->id, 'user_id' => $user->id, 'vehicle' => 'Motorbike KMEA 123A'])->assertCreated()->json('data');
        $this->assertSame(['Baraka Mwangi', 'Motorbike KMEA 123A'], [$created['name'], $created['vehicle']]);
        $this->api('POST', 'ecommerce/riders', ['company_id' => $this->acme->id, 'user_id' => $user->id])->assertUnprocessable()->assertJsonPath('code', 'rider_exists');
        $this->api('POST', 'ecommerce/riders', ['company_id' => $this->acme->id, 'user_id' => $user->id, 'employee_id' => (string) Str::uuid7()])->assertUnprocessable();

        // A cashier may not; the delivery board lists riders for a branch manager.
        $this->api('POST', 'ecommerce/riders', ['company_id' => $this->acme->id, 'user_id' => $user->id], $this->userWith('cashier', Scope::branch($this->branchA->id)))->assertForbidden();
        $this->assertCount(2, $this->api('GET', 'ecommerce/riders', [], $this->manager)->assertOk()->json('data'));

        $this->api('POST', "ecommerce/riders/{$created['id']}/archive")->assertOk()->assertJsonPath('data.active', false);
        $delivery = $this->assigned(assign: false);
        $this->api('POST', "ecommerce/deliveries/{$delivery->id}/assign", ['rider_id' => $created['id']], $this->manager)->assertUnprocessable()->assertJsonPath('code', 'rider_unavailable');
    }

    public function test_the_delivery_board_lists_open_deliveries_by_status(): void
    {
        $this->assigned();
        $this->assigned(assign: false);

        $board = $this->api('GET', 'ecommerce/deliveries', [], $this->manager)->assertOk()->json('data');
        $this->assertSame(['unassigned', 'assigned'], array_column($board, 'status'));
        $this->assertSame([], $this->api('GET', 'ecommerce/deliveries', [], $this->userWith('branch_manager', Scope::branch($this->branchB->id)))->json('data'));
    }

    /** A COD order of one widget, accepted, its delivery (assigned to the rider unless told not to). */
    private function assigned(bool $assign = true): Delivery
    {
        $id = $this->orderId($this->placeOrder($this->cart([[$this->widgetProduct, '1']]))->assertCreated()->json('data.number'));
        $this->api('POST', "ecommerce/orders/{$id}/accept", [], $this->manager)->assertOk();
        $delivery = $this->inTenant(fn () => Delivery::query()->where('order_id', $id)->sole());

        if ($assign) {
            $this->api('POST', "ecommerce/deliveries/{$delivery->id}/assign", ['rider_id' => $this->rider->id], $this->manager)->assertOk();
        }

        return $this->inTenant(fn () => $delivery->refresh());
    }

    private function update(Delivery $delivery, string $kind, array $extra = []): array
    {
        return ['id' => (string) Str::uuid7(), 'delivery_id' => $delivery->id, 'kind' => $kind, 'occurred_at' => CarbonImmutable::now()->toIso8601String(), ...$extra];
    }

    private function updates(array $updates, $as = null)
    {
        return $this->api('POST', 'ecommerce/rider/updates', ['updates' => $updates], $as ?? $this->riderUser);
    }
}

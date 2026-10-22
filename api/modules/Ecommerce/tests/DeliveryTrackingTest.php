<?php

namespace Modules\Ecommerce\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Http\ApiException;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Privacy\PrivacySubjects;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Ecommerce\Delivery\ProofPhotos;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\DeliveryLocation;
use Modules\Ecommerce\Models\DeliveryUpdate;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Tests\Concerns\BuildsEcommerce;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// ECOM-40 (rider location: trail while active, cleared when it ends, seen
// with ecommerce.delivery.track and by the order's customer), ECOM-41
// (photo proof through the media pipeline, seen by staff and the
// customer), ECOM-44 (WhatsApp order messages when opted in and
// configured).
class DeliveryTrackingTest extends TestCase
{
    use BuildsEcommerce, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEcommerce();
        Storage::fake('media');
    }

    public function test_the_rider_trail_is_kept_while_active_seen_with_tracking_and_cleared_when_delivered(): void
    {
        $delivery = $this->assigned();
        $this->updates([$this->update($delivery, 'status', ['status' => 'picked_up'])])->assertOk();
        $this->updates([
            $this->update($delivery, 'location', ['latitude' => '-1.2633', 'longitude' => '36.8040', 'occurred_at' => '2026-11-30T08:45:00Z']),
            $this->update($delivery, 'location', ['latitude' => '-1.2650', 'longitude' => '36.8100', 'occurred_at' => '2026-11-30T08:50:00Z']),
        ])->assertOk();

        // The update rows keep no position; the audit log never gets one (KE DPA: minimum data).
        $this->assertSame(0, $this->inTenant(fn () => DeliveryUpdate::query()->whereNotNull('latitude')->count()));
        $this->assertSame(0, $this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $delivery->id)->get()
            ->filter(fn ($e) => str_contains(json_encode([$e->before, $e->after]), 'latitude'))->count()));

        // The branch manager tracks it; an admin sees the board but not the position.
        $track = $this->api('GET', "ecommerce/deliveries/{$delivery->id}/track", [], $this->manager)->assertOk()->json('data');
        $this->assertSame([true, '-1.265000'], [$track['active'], $track['last_location']['latitude']]);
        $this->assertSame(['-1.263300', '-1.265000'], array_column($track['trail'], 'latitude'));
        $board = $this->api('GET', 'ecommerce/deliveries', [], $this->manager)->json('data.0');
        $this->assertSame([true, '36.810000'], [$board['can_track'], $board['last_location']['longitude']]);

        $admin = $this->userWith('admin', Scope::company($this->acme->id));
        $row = $this->api('GET', 'ecommerce/deliveries', [], $admin)->assertOk()->json('data.0');
        $this->assertSame([false, null], [$row['can_track'], $row['last_location']]);
        $this->api('GET', "ecommerce/deliveries/{$delivery->id}/track", [], $admin)->assertForbidden();
        $this->api('GET', "ecommerce/deliveries/{$delivery->id}/track", [], $this->userWith('branch_manager', Scope::branch($this->branchB->id)))->assertNotFound();
        $this->api('GET', "ecommerce/deliveries/{$delivery->id}/track", [], $this->riderUser)->assertNotFound();

        // The customer sees where the rider is while the order is on its way, never the trail.
        $order = $this->inTenant(fn () => Order::query()->findOrFail($delivery->order_id));
        $tracked = $this->shopCall('GET', "duka/orders/{$order->number}?t={$order->tracking_token_secret}")->assertOk()->json('data');
        $this->assertSame(['-1.265000', '36.810000'], [$tracked['rider_location']['latitude'], $tracked['rider_location']['longitude']]);
        $this->assertArrayNotHasKey('trail', $tracked);

        // Delivered: the trail is cleared, the last point stays; later points are refused.
        $this->updates([$this->update($delivery, 'status', ['status' => 'delivered', 'code' => $this->sentDeliveryCode()])])->assertOk();
        $this->assertSame(0, $this->inTenant(fn () => DeliveryLocation::query()->count()));
        $this->assertSame('-1.265000', (string) $delivery->refresh()->last_latitude);
        $this->assertSame([], $this->api('GET', "ecommerce/deliveries/{$delivery->id}/track", [], $this->manager)->json('data.trail'));
        $this->assertNull($this->shopCall('GET', "duka/orders/{$order->number}?t={$order->tracking_token_secret}")->json('data.rider_location'));
        $this->updates([$this->update($delivery, 'location', ['latitude' => '-1.3', 'longitude' => '36.9'])])->assertStatus(422)
            ->assertJsonPath('results.0.error.code', 'delivery_not_active');
    }

    public function test_a_new_rider_starts_without_the_previous_trail_and_the_trail_is_bounded(): void
    {
        config(['ecommerce.rider.max_trail_points' => 2]);
        $delivery = $this->assigned();
        $this->updates(array_map(fn (int $m) => $this->update($delivery, 'location', ['latitude' => '-1.26'.$m, 'longitude' => '36.80', 'occurred_at' => "2026-11-30T08:4{$m}:00Z"]), [1, 2, 3]))->assertOk();
        $this->assertSame(['-1.262000', '-1.263000'], $this->api('GET', "ecommerce/deliveries/{$delivery->id}/track", [], $this->manager)->json('data.trail.*.latitude'));

        // Failed before pick-up: back to the board; the trail and the last point go with the rider.
        $this->updates([$this->update($delivery, 'status', ['status' => 'failed', 'note' => 'Puncture'])])->assertOk();
        $delivery->refresh();
        $this->assertSame([0, null], [$this->inTenant(fn () => DeliveryLocation::query()->count()), $delivery->last_latitude]);
    }

    public function test_rider_positions_are_declared_personal_data_of_the_rider(): void
    {
        $tables = collect(app(PrivacySubjects::class)->sources('user'))->pluck('table')->all();

        foreach (['ecom_delivery_locations', 'ecom_deliveries', 'ecom_riders'] as $table) {
            $this->assertContains($table, $tables);
        }
    }

    public function test_the_trail_is_under_row_level_security(): void
    {
        $delivery = $this->assigned();
        $this->updates([$this->update($delivery, 'location', ['latitude' => '-1.2633', 'longitude' => '36.8040'])])->assertOk();
        $this->assertSame(1, $this->inTenant(fn () => DeliveryLocation::query()->count()));

        $other = $this->otherTenant();
        $this->inTenant(fn () => null);
        $this->assertSame(0, app(TenantContext::class)->run($other['user']->tenant_id, fn () => DeliveryLocation::query()->count()));
        app(TenantContext::class)->run($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('ecommerce'));
        $this->json('GET', "/api/v1/ecommerce/deliveries/{$delivery->id}/track", [], $this->bearer($this->tokenFor($other['user'])))->assertNotFound();
    }

    public function test_a_photo_proof_is_re_encoded_stored_privately_and_shown_to_staff_and_the_customer(): void
    {
        $delivery = $this->assigned();
        $this->updates([$this->update($delivery, 'status', ['status' => 'picked_up'])])->assertOk();
        $url = "/api/v1/ecommerce/rider/deliveries/{$delivery->id}/photo";

        // Size and type limits; another rider's delivery is not found; not an image is refused.
        config(['ecommerce.rider.max_photo_kb' => 50]);
        $this->json('POST', $url, ['photo' => UploadedFile::fake()->create('big.jpg', 80, 'image/jpeg')], $this->headersFor($this->riderUser))->assertUnprocessable()->assertJsonValidationErrors('photo');
        config(['ecommerce.rider.max_photo_kb' => 4096]);
        $this->json('POST', $url, ['photo' => UploadedFile::fake()->create('note.pdf', 10, 'application/pdf')], $this->headersFor($this->riderUser))->assertUnprocessable();
        $other = $this->userWith('rider', Scope::branch($this->branchA->id));
        $this->json('POST', $url, ['photo' => UploadedFile::fake()->image('door.jpg')], $this->headersFor($other))->assertNotFound();

        $this->json('POST', $url, ['photo' => UploadedFile::fake()->image('door.png', 40, 30)], $this->headersFor($this->riderUser))->assertCreated();
        $delivery->refresh();
        $this->assertStringStartsWith("tenants/{$delivery->tenant_id}/ecommerce/proofs/{$delivery->id}/", $delivery->proof_photo_path);
        $this->assertSame('image/jpeg', $delivery->proof_photo_mime, 're-encoded as a JPEG without metadata');
        Storage::disk('media')->assertExists($delivery->proof_photo_path);
        $bytes = Storage::disk('media')->get($delivery->proof_photo_path);
        $this->assertSame("\xFF\xD8", substr($bytes, 0, 2));
        $this->assertStringNotContainsString('Exif', $bytes);

        $this->updates([$this->update($delivery, 'status', ['status' => 'delivered'])])->assertOk();

        // Staff: a temporary link bound to the user; a tampered link is refused.
        $photo = $this->api('GET', "ecommerce/orders/{$delivery->order_id}", [], $this->manager)->assertOk()->json('data.delivery.proof_photo');
        $this->assertSame('image/jpeg', $photo['mime']);
        $this->get($photo['url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(str_replace('user=', 'user=0', $photo['url']))->assertForbidden();

        // The customer of the order, with its tracking token only.
        $order = $this->inTenant(fn () => Order::query()->findOrFail($delivery->order_id));
        $this->assertTrue($this->shopCall('GET', "duka/orders/{$order->number}?t={$order->tracking_token_secret}")->json('data.proof_photo'));
        $this->shopCall('GET', "duka/orders/{$order->number}/proof-photo?t={$order->tracking_token_secret}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->shopCall('GET', "duka/orders/{$order->number}/proof-photo")->assertNotFound();
        $this->shopCall('GET', "duka/orders/{$order->number}/proof-photo?t=".str_repeat('a', 40))->assertNotFound();
    }

    public function test_whatsapp_order_messages_go_only_when_opted_in_and_configured(): void
    {
        // Configured (the fake driver in testing) and asked for at checkout: SMS and WhatsApp.
        $this->assertTrue($this->shopCall('GET', 'duka')->json('data.whatsapp_notifications'));
        $this->assertSame('available', $this->api('GET', "ecommerce/stores/{$this->shop->id}", [], $this->manager)->json('data.whatsapp_notifications'));
        $this->placeOrder($this->cart([[$this->widgetProduct, '1']]), extra: ['whatsapp_opt_in' => true])->assertCreated()->assertJsonPath('data.whatsapp_opt_in', true);
        $this->assertSame(['email', 'sms', 'whatsapp'], $this->inTenant(fn () => NotificationDelivery::query()->where('event_type', 'ecommerce.order.placed')->orderBy('channel')->pluck('channel')->all()));
        $whatsapp = $this->inTenant(fn () => NotificationDelivery::query()->where('channel', 'whatsapp')->sole());
        $this->assertSame(['+254712345678', 'delivered'], [$whatsapp->recipient, $whatsapp->status]);

        // Not asked for: no WhatsApp.
        $this->placeOrder($this->cart([[$this->widgetProduct, '1']]), key: 'second-order-key-0001')->assertCreated();
        $this->assertSame(1, $this->inTenant(fn () => NotificationDelivery::query()->where('channel', 'whatsapp')->count()));

        // Not configured (no provider credentials): not offered, nothing queued.
        config(['notifications.drivers.whatsapp' => 'none']);
        $this->assertFalse($this->shopCall('GET', 'duka')->json('data.whatsapp_notifications'));
        $this->assertSame('not_configured', $this->api('GET', "ecommerce/stores/{$this->shop->id}", [], $this->manager)->json('data.whatsapp_notifications'));
        $this->placeOrder($this->cart([[$this->widgetProduct, '1']]), key: 'third-order-key-00001', extra: ['whatsapp_opt_in' => true])->assertCreated();
        $this->assertSame(1, $this->inTenant(fn () => NotificationDelivery::query()->where('channel', 'whatsapp')->count()));
    }

    public function test_a_photo_that_claims_a_huge_picture_is_refused_before_it_is_decoded(): void
    {
        // Review H3: a small file whose header claims 4000 × 3500 (14 MP) is refused from its header alone.
        $delivery = $this->assigned();
        $this->updates([$this->update($delivery, 'status', ['status' => 'picked_up'])])->assertOk();
        $url = "/api/v1/ecommerce/rider/deliveries/{$delivery->id}/photo";

        $this->json('POST', $url, ['photo' => $this->pngHeader(4000, 3500)], $this->headersFor($this->riderUser))
            ->assertUnprocessable()->assertJsonPath('code', 'photo_pixels_too_large');
        $this->json('POST', $url, ['photo' => $this->pngHeader(5000, 100)], $this->headersFor($this->riderUser))
            ->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->assertNull($delivery->refresh()->proof_photo_path);

        // What is kept is scaled down to 1600 pixels a side.
        $this->json('POST', $url, ['photo' => UploadedFile::fake()->image('door.jpg', 2000, 1000)], $this->headersFor($this->riderUser))->assertCreated();
        [$width, $height] = getimagesizefromstring(Storage::disk('media')->get($delivery->refresh()->proof_photo_path));
        $this->assertSame([1600, 800], [$width, $height]);
    }

    public function test_without_gd_no_photo_is_stored(): void
    {
        // Review L2: never stored with its metadata.
        $this->expectExceptionObject(new ApiException(503, 'photo_unavailable', __('ecommerce.errors.photo_unavailable')));
        ProofPhotos::withoutMetadata('bytes', 'image/jpeg', gd: false);
    }

    public function test_the_proof_never_changes_once_delivered_and_uploads_are_limited_per_rider(): void
    {
        // Review L1: a photo only while picked up; the delivered proof stays.
        $delivery = $this->assigned();
        $this->updates([$this->update($delivery, 'status', ['status' => 'picked_up'])])->assertOk();
        $url = "/api/v1/ecommerce/rider/deliveries/{$delivery->id}/photo";
        $this->json('POST', $url, ['photo' => UploadedFile::fake()->image('door.jpg')], $this->headersFor($this->riderUser))->assertCreated();
        $this->updates([$this->update($delivery, 'status', ['status' => 'delivered'])])->assertOk();
        $proof = $delivery->refresh()->proof_photo_path;
        $this->json('POST', $url, ['photo' => UploadedFile::fake()->image('other.jpg')], $this->headersFor($this->riderUser))->assertStatus(409)->assertJsonPath('code', 'delivery_status_invalid');
        $this->assertSame($proof, $delivery->refresh()->proof_photo_path);
        Storage::disk('media')->assertExists($proof);

        // Review H3, M5: 20 photo uploads and 30 update uploads a minute per rider.
        for ($i = 2; $i < 20; $i++) {
            $this->json('POST', $url, [], $this->headersFor($this->riderUser))->assertUnprocessable();
        }
        $this->json('POST', $url, [], $this->headersFor($this->riderUser))->assertStatus(429);

        for ($i = 0; $i < 28; $i++) {
            $this->api('POST', 'ecommerce/rider/updates', [], $this->riderUser)->assertUnprocessable();
        }
        $this->api('POST', 'ecommerce/rider/updates', [], $this->riderUser)->assertStatus(429);
    }

    public function test_the_proof_photo_needs_the_orders_own_token_and_store(): void
    {
        $delivery = $this->assigned();
        $this->updates([$this->update($delivery, 'status', ['status' => 'picked_up'])])->assertOk();
        $this->json('POST', "/api/v1/ecommerce/rider/deliveries/{$delivery->id}/photo", ['photo' => UploadedFile::fake()->image('door.jpg')], $this->headersFor($this->riderUser))->assertCreated();
        $this->updates([$this->update($delivery, 'status', ['status' => 'delivered'])])->assertOk();
        $order = $this->inTenant(fn () => Order::query()->findOrFail($delivery->order_id));
        $otherId = $this->orderId($this->placeOrder($this->cart([[$this->widgetProduct, '1']]))->assertCreated()->json('data.number'));
        $other = $this->inTenant(fn () => Order::query()->findOrFail($otherId));
        $this->inTenant(fn () => Store::create([...$this->shop->only(['company_id', 'branch_id', 'location_id', 'currency', 'price_list_id', 'payment_methods']),
            'handle' => 'second', 'name' => 'Second', 'status' => 'live', 'delivery_enabled' => true]));

        // Another order's token, and this order's token at another store of the tenant: not found.
        $this->shopCall('GET', "duka/orders/{$order->number}/proof-photo?t={$other->tracking_token_secret}")->assertNotFound();
        $this->shopCall('GET', "second/orders/{$order->number}/proof-photo?t={$order->tracking_token_secret}")->assertNotFound();
        $this->shopCall('GET', "duka/orders/{$order->number}/proof-photo?t={$order->tracking_token_secret}")->assertOk();
    }

    /** A PNG that is only a header: it claims $width × $height pixels and holds none. */
    private function pngHeader(int $width, int $height): UploadedFile
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IDAT', (string) gzcompress('')).$chunk('IEND', ''));

        return new UploadedFile($path, 'big.png', 'image/png', null, true);
    }

    private function assigned(): Delivery
    {
        $id = $this->orderId($this->placeOrder($this->cart([[$this->widgetProduct, '1']]))->assertCreated()->json('data.number'));
        $this->api('POST', "ecommerce/orders/{$id}/accept", [], $this->manager)->assertOk();
        $delivery = $this->inTenant(fn () => Delivery::query()->where('order_id', $id)->sole());
        $this->api('POST', "ecommerce/deliveries/{$delivery->id}/assign", ['rider_id' => $this->rider->id], $this->manager)->assertOk();

        return $this->inTenant(fn () => $delivery->refresh());
    }

    private function update(Delivery $delivery, string $kind, array $extra = []): array
    {
        return ['id' => (string) Str::uuid7(), 'delivery_id' => $delivery->id, 'kind' => $kind, 'occurred_at' => CarbonImmutable::now()->toIso8601String(), ...$extra];
    }

    private function updates(array $updates)
    {
        return $this->api('POST', 'ecommerce/rider/updates', ['updates' => $updates], $this->riderUser);
    }
}

<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Items\DefaultUoms;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemImage;
use App\Core\MasterData\Items\Uom;
use App\Core\Rbac\Scope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MD-02: item images on the media disk under tenants/{tenant}/items/{item}/,
// JPEG/PNG/WebP up to 2 MB, at most 8 per item, served only through
// temporary signed URLs that check the tenant and the viewer again. Images
// are files: a delete removes the file and the row, audited on the item.
class ItemImageApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private string $item;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        $this->setUpOrganisation();
        $ea = $this->inTenant(function () {
            app(DefaultUoms::class)->seed();

            return Uom::query()->where('code', 'EA')->value('id');
        });
        $this->item = $this->postJson('/api/v1/items', ['code' => 'IMG-1', 'name_en' => 'Lamp', 'type' => 'stock', 'base_uom_id' => $ea], $this->headersFor())
            ->assertCreated()->json('data.id');
    }

    private function upload(UploadedFile $file, ?array $headers = null, ?string $item = null)
    {
        return $this->post('/api/v1/items/'.($item ?? $this->item).'/images', ['image' => $file], ['Accept' => 'application/json', ...($headers ?? $this->headersFor())]);
    }

    public function test_an_image_is_stored_under_the_tenant_and_item_and_served_by_a_signed_url(): void
    {
        $response = $this->upload(UploadedFile::fake()->image('lamp.jpg', 640, 480))->assertCreated();
        $image = $response->json('data.images.0');
        $this->assertSame([1, 'image/jpeg', 640, 480], [$image['position'], $image['mime'], $image['width'], $image['height']]);

        $path = $this->inTenant(fn () => ItemImage::findOrFail($image['id'])->path);
        $this->assertMatchesRegularExpression("#^tenants/{$this->owner->tenant_id}/items/{$this->item}/[0-9a-f\-]{36}\.jpg$#", $path);
        Storage::disk('media')->assertExists($path);

        // The URL is signed, temporary and names no public storage path.
        $this->assertStringContainsString('/api/v1/media/', $image['url']);
        $this->assertStringContainsString('signature=', $image['url']);
        $this->assertStringContainsString('expires=', $image['url']);
        $this->get($image['url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // Tampered or expired: refused.
        $this->get(str_replace('signature=', 'signature=0', $image['url']))->assertForbidden();
        $this->travel(16)->minutes();
        $this->get($image['url'])->assertForbidden();
    }

    public function test_the_signed_url_checks_the_viewer_and_the_tenant_again(): void
    {
        $clerk = $this->userWith('cashier', Scope::location($this->locationA->id));
        $url = $this->upload(UploadedFile::fake()->image('a.png', 10, 10))->assertCreated()->json('data.images.0.url');
        $clerkUrl = $this->getJson("/api/v1/items/{$this->item}", $this->headersFor($clerk))->assertOk()->json('data.images.0.url');

        $this->get($clerkUrl)->assertOk();

        // The signed-for user lost access: not found.
        $this->inTenant(fn () => $clerk->update(['status' => 'deactivated']));
        $this->get($clerkUrl)->assertNotFound();

        // The URL is bound to its user: another user id breaks the signature.
        $this->get(preg_replace('/user=[^&]+/', 'user='.$clerk->id, $url))->assertForbidden();

        // A signed URL of a path in another tenant finds no image there.
        $other = $this->otherTenant();
        $foreign = URL::temporarySignedRoute('media.show', now()->addMinutes(5), [
            'path' => preg_replace('#^tenants/[^/]+/#', "tenants/{$other['user']->tenant_id}/", $this->inTenant(fn () => ItemImage::query()->sole()->path)),
            'user' => $other['user']->id,
        ]);
        $this->get($foreign)->assertNotFound();
    }

    public function test_uploads_are_validated(): void
    {
        $this->upload(UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'))->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->upload(UploadedFile::fake()->create('fake.jpg', 10, 'image/jpeg'))->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->upload(UploadedFile::fake()->image('huge.jpg', 100, 100)->size(2049))->assertUnprocessable()
            ->assertJsonPath('errors.image.0', __('core.item.image_too_large', ['max' => '2 MB']));
        $this->upload(UploadedFile::fake()->image('ok.png', 10, 10)->size(2048))->assertCreated();

        $this->assertCount(1, Storage::disk('media')->allFiles());
    }

    public function test_at_most_eight_images_reordered_and_deleted(): void
    {
        foreach (range(1, Item::MAX_IMAGES) as $n) {
            $this->upload(UploadedFile::fake()->image("p{$n}.jpg", 10, 10))->assertCreated();
        }

        $this->upload(UploadedFile::fake()->image('p9.jpg', 10, 10))->assertUnprocessable()->assertJsonPath('code', 'image_limit');
        $this->assertCount(Item::MAX_IMAGES, Storage::disk('media')->allFiles());

        $ids = array_column($this->getJson("/api/v1/items/{$this->item}", $this->headersFor())->json('data.images'), 'id');
        $reversed = array_reverse($ids);

        $this->putJson("/api/v1/items/{$this->item}/images/order", ['image_ids' => array_slice($reversed, 1)], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'image_order_invalid');
        $this->putJson("/api/v1/items/{$this->item}/images/order", ['image_ids' => $reversed], $this->headersFor())->assertOk()
            ->assertJsonPath('data.images.0.id', $reversed[0])->assertJsonPath('data.images.0.position', 1);

        $path = $this->inTenant(fn () => ItemImage::findOrFail($reversed[0])->path);
        $this->deleteJson("/api/v1/item-images/{$reversed[0]}", [], $this->headersFor())->assertNoContent();
        Storage::disk('media')->assertMissing($path);

        $after = $this->getJson("/api/v1/items/{$this->item}", $this->headersFor())->json('data.images');
        $this->assertSame(range(1, Item::MAX_IMAGES - 1), array_column($after, 'position'));
        $this->assertSame(array_slice($reversed, 1), array_column($after, 'id'));

        $this->inTenant(function () use ($reversed) {
            $this->assertSame(Item::MAX_IMAGES, AuditEntry::where('action', 'core.item.image_add')->where('auditable_id', $this->item)->count());
            $this->assertSame(1, AuditEntry::where('action', 'core.item.images_reorder')->count());
            $this->assertSame($reversed[0], AuditEntry::where('action', 'core.item.image_delete')->sole()->before['images'][0]['id']);
        });

        $this->getJson("/api/v1/history/item/{$this->item}", $this->headersFor())->assertOk()->assertJsonPath('data.0.action', 'core.item.image_delete');
    }

    public function test_images_are_changed_with_edit_permission_and_not_across_tenants(): void
    {
        $id = $this->upload(UploadedFile::fake()->image('a.jpg', 10, 10))->assertCreated()->json('data.images.0.id');

        $cashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));
        $this->upload(UploadedFile::fake()->image('b.jpg', 10, 10), $cashier)->assertForbidden();
        $this->deleteJson("/api/v1/item-images/{$id}", [], $cashier)->assertForbidden();
        $this->putJson("/api/v1/items/{$this->item}/images/order", ['image_ids' => [$id]], $cashier)->assertForbidden();

        $other = $this->otherTenant();
        $otherHeaders = $this->headersFor($other['user']);
        $this->deleteJson("/api/v1/item-images/{$id}", [], $otherHeaders)->assertNotFound();
        $this->upload(UploadedFile::fake()->image('c.jpg', 10, 10), $otherHeaders)->assertNotFound();

        $this->inTenant(fn () => $this->assertSame(1, ItemImage::count()));
        $this->assertCount(1, Storage::disk('media')->allFiles());
    }
}

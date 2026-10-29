<?php

namespace App\Core\Manual\Demo;

use App\Core\Identity\Pin\ActorProofVerifier;
use App\Core\Manual\DemoTenantCommand;
use App\Core\Sync\DeviceSecrets;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Realistic Kenyan sample data for the demo tenant, module by module, made
 * through the application's own API (see DemoApi). Every section checks what
 * already exists first, so a second run adds nothing; a section that fails is
 * reported and the others still run.
 */
class DemoData
{
    /** The till PIN of every demo user. */
    public const TILL_PIN = '739152';

    /** @var array<string, mixed> */
    public array $ids = [];

    public function __construct(public readonly DemoApi $api, private readonly Command $console) {}

    /** @param  list<string>  $only */
    public function run(array $only = []): void
    {
        $this->base();

        foreach (['inventory', 'procurement', 'pos', 'accounting', 'hr', 'payroll', 'hotel', 'restaurant', 'pharmacy', 'vansales', 'fuel', 'salon', 'crm', 'loyalty', 'ecommerce', 'approvals'] as $name) {
            $this->section($name, $only);
        }

        // Settings screens that would be empty without a record (their own section file).
        $this->section('settings', $only);
    }

    /**
     * One data section by name, unless --only leaves it out. A section that fails is reported and the others still run.
     *
     * @param  list<string>  $only
     */
    private function section(string $name, array $only): void
    {
        if ($only !== [] && ! in_array($name, $only, true)) {
            return;
        }

        $class = __NAMESPACE__.'\\Sections\\'.str_replace(' ', '', ucwords(str_replace('_', ' ', $name))).'Section';

        if (! class_exists($class)) {
            return;
        }

        try {
            $this->console->line("Demo data: {$name}");
            (new $class)->run($this);
        } catch (Throwable $e) {
            $this->console->warn("Demo data: {$name} stopped: ".$e->getMessage());
        }
    }

    /** The id of a demo user by role key. */
    public function userId(string $roleKey): string
    {
        return collect($this->api->rows('users'))->firstWhere('email', 'demo-'.str_replace('_', '-', $roleKey).'@'.DemoTenantCommand::DOMAIN)['id'];
    }

    /**
     * A till paired at a location through the API (AUTH-06).
     *
     * @return array{client: DemoApi, id: string, kid: string, secret: string}
     */
    public function pairDevice(string $location, string $name): array
    {
        $device = $this->api->post("locations/{$location}/devices", ['name' => $name])['data'];
        $code = $this->api->post("devices/{$device['id']}/pairing-code")['code'];
        $paired = $this->api->send('POST', 'devices/pair', ['code' => $code, 'device_name' => $name], signedIn: false);

        return ['client' => $this->api->bearer($paired['token']), 'id' => $device['id'], 'kid' => $paired['device_secret_kid'], 'secret' => $paired['device_secret']];
    }

    /**
     * The sign-in attestation a till signs with its secret for a user (AUTH-07).
     *
     * @param  array{id: string, kid: string, secret: string}  $device
     * @return array<string, string>
     */
    public function actorProof(array $device, string $userId): array
    {
        $fields = ['session_id' => (string) Str::uuid7(), 'user_id' => $userId, 'signed_in_at' => now()->subHours(9)->utc()->format('Y-m-d\TH:i:s.v\Z'), 'kid' => $device['kid']];
        $message = ActorProofVerifier::message($device['id'], $fields['kid'], $fields['session_id'], $fields['user_id'], $fields['signed_in_at']);

        return $fields + ['signature' => DeviceSecrets::encode(hash_hmac('sha256', $message, DeviceSecrets::decode($device['secret']), true))];
    }

    /** An item with a price in the default price list, taxed with the standard VAT category. @return array<string, mixed> */
    public function item(string $code, string $name, string $type, int $priceMinor, ?string $categoryId = null, string $uomCode = 'EA'): array
    {
        $company = $this->ids['company'];
        $uom = collect($this->api->rows('uoms'))->firstWhere('code', $uomCode);
        $item = $this->api->post('items', array_filter([
            'code' => $code, 'name' => $name, 'type' => $type, 'base_uom_id' => $uom['id'], 'category_id' => $categoryId,
            'tax_category_id' => $this->api->rows('tax-categories')[0]['id'],
        ]))['data'];
        $list = collect($this->api->rows("companies/{$company}/price-lists"))->firstWhere('is_default', true);
        $this->api->post("price-lists/{$list['id']}/prices", ['item_id' => $item['id'], 'uom_id' => $uom['id'], 'amount_minor' => (string) $priceMinor, 'currency' => $this->ids['currency']]);

        return $item + ['uom_id' => $uom['id']];
    }

    /** Approves every pending approval in a demo user's inbox (the workflow's own approver). */
    public function approveAll(string $roleKey): void
    {
        $user = $this->api->as($roleKey);

        foreach ($user->rows('approvals') as $approval) {
            if ($approval['status'] === 'pending') {
                $user->post("approvals/{$approval['id']}/approve");
            }
        }
    }

    /** The company, branches, locations and sample items every section works from. */
    private function base(): void
    {
        $company = $this->api->rows('companies')[0];
        $branches = $this->api->rows('branches', ['company_id' => $company['id']]);
        $this->ids['company'] = $company['id'];
        $this->ids['currency'] = $company['base_currency'];
        $this->ids['branches'] = array_column($branches, 'id', 'code');
        $locations = [];

        foreach ($this->api->rows('locations') as $location) {
            $locations[$location['branch_id'] ?? $location['branch']['id'] ?? ''][] = $location['id'];
        }

        $this->ids['locations'] = array_values(array_map(fn ($list) => $list[0], $locations));
        $this->ids['items'] = array_column($this->api->rows('items'), 'id');
        $this->standardVat();
        $this->tillPins();
        $this->mpesa();
    }

    /** M-Pesa switched on with the Daraja sandbox's public test shortcode and placeholder keys, so the till offers it (PAY-02). Never real credentials. */
    private function mpesa(): void
    {
        $method = collect($this->api->rows("companies/{$this->ids['company']}/payment-methods"))->firstWhere('name', 'M-Pesa');

        if ($method !== null && $method['active'] === false) {
            $this->api->patch("payment-methods/{$method['id']}", [
                'currency' => 'KES', 'settings' => ['shortcode' => '174379'],
                'secrets' => ['consumer_key' => 'demo-consumer-key', 'consumer_secret' => 'demo-consumer-secret', 'passkey' => 'demo-passkey'], 'active' => true,
            ]);
        }
    }

    /** A till PIN for every demo user, so the POS app can be signed in to (AUTH-06). */
    private function tillPins(): void
    {
        foreach ($this->api->rows('users') as $user) {
            // Each user sets their own PIN, so the till does not ask them to choose a new one.
            (new DemoApi($user['email'], DemoTenantCommand::PASSWORD))->put('me/pos-pin', ['password' => DemoTenantCommand::PASSWORD, 'pin' => self::TILL_PIN]);
        }
    }

    /** The country pack's standard VAT code on every sample item, through a tax category (MD-03, CP-01). */
    private function standardVat(): void
    {
        $company = $this->ids['company'];
        $vat = collect($this->api->rows("companies/{$company}/tax-codes"))->firstWhere('code', 'VAT_STD');
        $this->ids['vat'] = ['id' => $vat['id'], 'rate' => $vat['current_rate']['rate']];

        $category = $this->api->rows('tax-categories')[0]['id'] ?? $this->api->post('tax-categories', [
            'name' => 'Standard-rated goods',
            'codes' => [['company_id' => $company, 'tax_code_id' => $vat['id']]],
        ])['data']['id'];

        foreach ($this->api->rows('items') as $item) {
            if ($item['tax_category_id'] === null) {
                $this->api->patch("items/{$item['id']}", ['tax_category_id' => $category]);
            }
        }
    }
}

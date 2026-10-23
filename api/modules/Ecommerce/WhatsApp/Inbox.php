<?php

namespace Modules\Ecommerce\WhatsApp;

use App\Core\Identity\Models\User;
use App\Core\Privacy\Contracts\ReceivesOptOutReplies;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Ecommerce\Customers\Customers;
use Modules\Ecommerce\EcommerceServiceProvider;
use Modules\Ecommerce\Models\Conversation;
use Modules\Ecommerce\Models\Message;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Support\EcomError;
use Modules\Ecommerce\Support\Tx;

/**
 * ECOM-24, ECOM-25: WhatsApp messages in and out. The webhook's token
 * (40 characters, stored as sha256 on the store) names the store; its
 * tenant is found by the security-definer function `ecom_whatsapp_tenant`
 * (ADR 002) and entered for the request. The provider verifies the
 * request; each message is stored once by the provider's message id, in a
 * conversation per store and phone (an older conversation that was closed
 * or converted opens again). Replies go out through the provider and are
 * kept with who sent them. A reply that is an opt-out keyword (STOP)
 * also withdraws the customer's marketing consent on every channel (NFR-08;
 * owner decision 2026-10-22).
 */
final class Inbox
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly WhatsAppProviders $providers,
        private readonly ReceivesOptOutReplies $optOuts,
    ) {}

    /** The live store of a webhook token, in its tenant (entered), or null. */
    public function storeFor(string $token): ?Store
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return null;
        }

        $hash = hash('sha256', $token);
        $tenantId = DB::connection(TenantContext::CONNECTION)->selectOne('select public.ecom_whatsapp_tenant(?) as tenant_id', [$hash])?->tenant_id;

        if ($tenantId === null) {
            return null;
        }

        $this->tenants->set($tenantId);

        if (Tenant::query()->whereKey($tenantId)->value('status') !== 'active' || ! $this->modules->isActive(EcommerceServiceProvider::MODULE)) {
            return null;
        }

        return Store::query()->where('whatsapp_token_hash', $hash)->whereNull('archived_at')->first();
    }

    /** @return int the messages stored (repeats are not) */
    public function receive(Store $store, Request $request): int
    {
        $provider = $this->providers->current();

        if ($provider === null || ! $provider->verify($request, $store)) {
            throw EcomError::make('webhook_unverified', status: 401);
        }

        $company = Company::query()->findOrFail($store->company_id);
        $stored = 0;

        foreach ($provider->messages($request) as $message) {
            $phone = Customers::phone($message['from'], $company) ?? Customers::phone('+'.ltrim($message['from'], '+'), $company);

            if ($phone === null) {
                continue;
            }

            $new = Tx::run(function () use ($store, $phone, $message) {
                if (Message::query()->where('store_id', $store->id)->where('provider_message_id', $message['id'])->exists()) {
                    return 0;
                }

                $at = CarbonImmutable::parse($message['at'])->utc();
                $conversation = Conversation::query()->where('store_id', $store->id)->where('phone', $phone)->lockForUpdate()->first()
                    ?? Conversation::create(['store_id' => $store->id, 'phone' => $phone, 'name' => $message['name'], 'status' => 'open']);

                Message::create(['conversation_id' => $conversation->id, 'store_id' => $store->id, 'direction' => 'in', 'body' => $message['text'],
                    'provider_message_id' => $message['id'], 'status' => 'received', 'at' => $at]);
                $conversation->forceFill([
                    'status' => 'open',
                    'order_id' => $conversation->status === 'open' ? $conversation->order_id : null,
                    'name' => $conversation->name ?? $message['name'],
                    'last_message_at' => $at,
                ])->save();

                return 1;
            });
            $stored += $new;

            // NFR-08: a STOP reply opts the customer out of marketing on every channel (core consent).
            if ($new === 1 && $this->optOuts->isOptOut($message['text'])) {
                $this->optOuts->optOut('whatsapp', $phone, 'store_whatsapp');
            }
        }

        return $stored;
    }

    public function reply(Conversation $conversation, string $text, User $by): Message
    {
        $store = Store::query()->findOrFail($conversation->store_id);
        $provider = $this->providers->current();

        if ($provider === null) {
            throw EcomError::make('whatsapp_unavailable');
        }

        $result = $provider->send($store, $conversation->phone, $text);

        return Tx::run(function () use ($conversation, $store, $text, $by, $result) {
            $message = Message::create(['conversation_id' => $conversation->id, 'store_id' => $store->id, 'direction' => 'out', 'body' => $text,
                'provider_message_id' => $result['id'], 'sent_by' => $by->id, 'status' => $result['sent'] ? 'sent' : 'failed', 'at' => CarbonImmutable::now()]);
            $conversation->forceFill(['last_message_at' => $message->at])->save();

            return $message;
        });
    }
}

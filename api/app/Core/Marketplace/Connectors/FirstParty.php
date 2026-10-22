<?php

namespace App\Core\Marketplace\Connectors;

/**
 * MKT-04 (docs/adr/024-marketplace.md): the first-party connectors, seeded
 * as listed apps of the platform's own developer by migration
 * `2026_12_15_000200_seed_first_party_connectors`, with fixed ids so every
 * environment has the same ones. Keys match `marketplace.connectors`.
 * Texts are stored once (names and texts are not per-language).
 */
final class FirstParty
{
    public const DEVELOPER = '019a0000-0000-7000-8000-00000000d001';

    /** @var array<string, array{app: string, version: string, client: string, slug: string, name: string, tagline: string, description: string, category: string, scopes: list<string>, events: list<string>}> */
    public const CONNECTORS = [
        'courier' => [
            'app' => '019a0000-0000-7000-8000-00000000a001',
            'version' => '019a0000-0000-7000-8000-00000000b001',
            'client' => '019a0000-0000-7000-8000-00000000c001',
            'slug' => 'courier-connector',
            'name' => 'Courier connector',
            'tagline' => 'Book deliveries for online orders with a courier.',
            'description' => 'Books a courier pickup when an online order is paid, and writes the courier\'s tracking back to the delivery. Works with any courier the platform adds through its courier interface.',
            'category' => 'delivery',
            'scopes' => ['core.location.read', 'core.party.read', 'ecommerce.delivery.write', 'ecommerce.order.read'],
            'events' => ['order.paid.v1', 'order.status_changed.v1'],
        ],
        'messaging' => [
            'app' => '019a0000-0000-7000-8000-00000000a002',
            'version' => '019a0000-0000-7000-8000-00000000b002',
            'client' => '019a0000-0000-7000-8000-00000000c002',
            'slug' => 'sms-whatsapp-connector',
            'name' => 'SMS and WhatsApp connector',
            'tagline' => 'Send receipts and order updates to customers by SMS or WhatsApp.',
            'description' => 'Sends a receipt after each sale and an update when an online order changes, by SMS or WhatsApp, through the platform\'s message providers. Customers without a phone number are skipped.',
            'category' => 'messaging',
            'scopes' => ['core.party.read', 'ecommerce.order.read', 'pos.sale.read'],
            'events' => ['order.status_changed.v1', 'sale.completed.v1'],
        ],
        'bi' => [
            'app' => '019a0000-0000-7000-8000-00000000a003',
            'version' => '019a0000-0000-7000-8000-00000000b003',
            'client' => '019a0000-0000-7000-8000-00000000c003',
            'slug' => 'power-bi-excel-connector',
            'name' => 'Power BI and Excel connector',
            'tagline' => 'Refreshable feeds of your sales, stock, items and customers.',
            'description' => 'Gives Power BI and Excel refreshable feeds of sales, stock, items, customers and places, read through the public API as CSV. The feeds show only what the connector\'s role may see, and never the fields its rules hide.',
            'category' => 'analytics',
            'scopes' => ['core.branch.read', 'core.company.read', 'core.item.read', 'core.item_category.read', 'core.location.read', 'core.party.read', 'inventory.stock.read', 'pos.sale.read'],
            'events' => [],
        ],
        'biometric' => [
            'app' => '019a0000-0000-7000-8000-00000000a004',
            'version' => '019a0000-0000-7000-8000-00000000b004',
            'client' => '019a0000-0000-7000-8000-00000000c004',
            'slug' => 'biometric-attendance-connector',
            'name' => 'Biometric attendance connector',
            'tagline' => 'Bring punches from fingerprint and face terminals into HR.',
            'description' => 'Collects clock-in and clock-out punches from biometric terminals and sends them to HR through the attendance device API, matched to employees by their enrolment reference.',
            'category' => 'attendance',
            'scopes' => ['hr.attendance.write', 'hr.device.read', 'hr.employee.read'],
            'events' => [],
        ],
    ];

    /** @return array<string, string> connector key => app id */
    public static function appIds(): array
    {
        return array_map(fn (array $connector) => $connector['app'], self::CONNECTORS);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// NFR-05 (stage 8C): pos_sales is the largest table, and two of its foreign
// keys had no index: a customer's sales (customer history, data subject
// access requests, NFR-08) and a device's sales (sync and device reports).
// Both lead with tenant_id, as every query runs under row-level security;
// the customer index is partial (most sales have no customer).
// CONCURRENTLY, outside a transaction, so the build never blocks the tills.
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('create index concurrently if not exists pos_sales_tenant_id_customer_id_sold_at_index on pos_sales (tenant_id, customer_id, sold_at) where customer_id is not null');
        DB::statement('create index concurrently if not exists pos_sales_tenant_id_device_id_sold_at_index on pos_sales (tenant_id, device_id, sold_at)');
    }

    public function down(): void
    {
        DB::statement('drop index concurrently if exists pos_sales_tenant_id_customer_id_sold_at_index');
        DB::statement('drop index concurrently if exists pos_sales_tenant_id_device_id_sold_at_index');
    }
};

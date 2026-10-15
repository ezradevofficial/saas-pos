<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stage 7B closing review (procurement.md, decisions 16 to 19):
// - receipt lines record whether their stock was tracked, i.e. whether the
//   GRN raised GoodsReceived for them with Inventory active, so an invoice
//   clears GRNI only over what a receipt journal credited, whatever the
//   modules are when it is approved (PRC-14, PRC-15, ACC-32);
// - receipt lines keep what Inventory or Stores could not receive (unknown
//   item or unit, a serial already in stock) as visible exceptions (INV-15);
// - invoice lines keep the base net of their tracked stock share, the part
//   that meets GRNI (the rest goes to purchases);
// - landed cost charges name the supplier's bill reference, so Accounting
//   can post the charge's bill (PRC-17, ACC-32).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_receipt_lines', function (Blueprint $table) {
            $table->boolean('stock_tracked')->default(false);
            $table->jsonb('receive_exceptions')->default('[]');
        });

        Schema::table('procurement_invoice_lines', function (Blueprint $table) {
            $table->bigInteger('base_stock_net_minor')->nullable();
        });

        Schema::table('procurement_landed_cost_charges', function (Blueprint $table) {
            $table->string('bill_reference', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('procurement_landed_cost_charges', function (Blueprint $table) {
            $table->dropColumn('bill_reference');
        });

        Schema::table('procurement_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('base_stock_net_minor');
        });

        Schema::table('procurement_receipt_lines', function (Blueprint $table) {
            $table->dropColumn(['stock_tracked', 'receive_exceptions']);
        });
    }
};

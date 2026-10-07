<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TEN-04: branches of a company.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('timezone')->nullable();
            $table->jsonb('address')->default('{}');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
        });

        Rls::enable('branches');
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};

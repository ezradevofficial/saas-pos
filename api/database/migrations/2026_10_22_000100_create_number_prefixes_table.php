<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NUM-01, M2: every concrete prefix a number sequence has issued (its
// format's pattern with the place codes, and a yearly format's year,
// filled in), so a format or code change that would make one sequence
// print numbers another already printed can be refused (NumberPrefixes).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_prefixes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('number_sequence_id')->constrained()->restrictOnDelete();
            $table->string('prefix', 200);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['number_sequence_id', 'prefix']);
        });

        Rls::enable('number_prefixes');
    }

    public function down(): void
    {
        Schema::dropIfExists('number_prefixes');
    }
};

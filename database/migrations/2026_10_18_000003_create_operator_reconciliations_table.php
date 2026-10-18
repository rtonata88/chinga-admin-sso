<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily match of our seamless movements against the operator's statement
 * (spec, "Reconciliation and reporting"): equal, only on our side, only on theirs,
 * and different. One row per operator per day; a re-run replaces it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_integration_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('status', ['matched', 'differences', 'unavailable']);
            $table->unsignedInteger('equal')->default(0);
            $table->unsignedInteger('only_ours')->default(0);
            $table->unsignedInteger('only_theirs')->default(0);
            $table->unsignedInteger('different')->default(0);
            $table->unsignedInteger('unreadable_lines')->default(0);
            $table->json('details')->nullable();
            $table->timestamps();
            $table->unique(['operator_integration_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_reconciliations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issued reseller invoices and the payments recorded against them. An
 * invoice freezes the figures the preview computed from the game engines
 * at the moment it was issued, so what a tenant was billed never drifts.
 * Status is derived from the payments and stored for listing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->string('currency', 3)->default('NAD');
            $table->string('status', 16)->default('issued'); // issued | part_paid | paid | void
            $table->unsignedInteger('bets_placed')->default(0);
            $table->unsignedInteger('active_players')->default(0);
            $table->decimal('total_wagered', 15, 2)->default(0);
            $table->decimal('total_paid_out', 15, 2)->default(0);
            $table->decimal('ggr', 15, 2)->default(0);
            $table->decimal('tax_pct', 5, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('ngr', 15, 2)->default(0);
            $table->decimal('revenue_share_pct', 5, 2)->default(0);
            $table->decimal('tenant_share', 15, 2)->default(0);
            $table->decimal('amount_due', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->json('games')->nullable();        // per-game lines as printed
            $table->json('unavailable')->nullable();  // backends that could not be read when issued
            $table->timestamp('issued_at');
            $table->date('due_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('period_from');
        });

        Schema::create('tenant_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_invoice_id')->constrained('tenant_invoices')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('paid_at');
            $table->string('method', 24)->default('bank_transfer'); // bank_transfer | cash | other
            $table->string('reference')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_invoice_payments');
        Schema::dropIfExists('tenant_invoices');
    }
};

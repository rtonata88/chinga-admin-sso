<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The figures the treasury view cannot read from the ledger: the cash
 * actually in the business account (entered by hand from the bank), the
 * variance reserve the operator chooses to hold, and the gaming tax
 * rate used for the provision. One row, platform-wide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('bank_balance', 15, 2)->default(0);
            $table->date('bank_balance_as_of')->nullable();
            $table->decimal('variance_reserve', 15, 2)->default(0);
            $table->decimal('tax_pct', 5, 2)->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_settings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('venue_staff', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('venue_id')->constrained()->onDelete('cascade');
            $table->string('username', 50);
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('password');
            $table->string('pin')->nullable(); // Hashed 4-digit PIN for quick POS access
            $table->string('display_name', 100);
            $table->enum('role', ['owner', 'manager', 'staff', 'cashier'])->default('staff');
            $table->enum('status', ['active', 'suspended', 'terminated'])->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['venue_id', 'username']);
            $table->index('status');
            $table->index('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venue_staff');
    }
};

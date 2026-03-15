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
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->string('business_name')->nullable();
            $table->string('license_number', 100)->nullable();
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('city', 100);
            $table->string('region', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2)->default('NA');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('timezone', 50)->default('Africa/Windhoek');
            $table->char('currency', 3)->default('NAD');
            $table->enum('status', ['active', 'suspended', 'closed'])->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('country_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('legal_name')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('license_number')->nullable();
            $table->string('contact_email');
            $table->string('contact_phone')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('domain')->nullable()->unique();
            $table->string('country_code', 2)->default('NA');
            $table->string('currency', 3)->default('NAD');
            $table->string('timezone')->default('Africa/Windhoek');
            $table->enum('status', ['active', 'suspended', 'terminated'])->default('active');
            $table->json('settings')->nullable();
            $table->decimal('revenue_share_pct', 5, 2)->default(0);
            $table->timestamp('contract_starts_at')->nullable();
            $table->timestamp('contract_ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};

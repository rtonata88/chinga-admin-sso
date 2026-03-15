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
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('ip_address', 45)->index();
            $table->boolean('successful')->default(false);
            $table->string('failure_reason', 100)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('country_code', 3)->nullable();
            $table->string('city', 100)->nullable();
            $table->timestamps();

            $table->index(['email', 'successful', 'created_at']);
            $table->index(['ip_address', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};

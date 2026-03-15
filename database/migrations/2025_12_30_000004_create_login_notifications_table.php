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
        Schema::create('login_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('ip_address', 45);
            $table->text('user_agent');
            $table->string('device_fingerprint')->nullable();
            $table->string('device_type', 50)->nullable();
            $table->string('browser', 100)->nullable();
            $table->string('platform', 100)->nullable();
            $table->string('country_code', 3)->nullable();
            $table->string('city', 100)->nullable();
            $table->boolean('is_new_device')->default(false);
            $table->boolean('is_new_location')->default(false);
            $table->boolean('notification_sent')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'device_fingerprint']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_notifications');
    }
};

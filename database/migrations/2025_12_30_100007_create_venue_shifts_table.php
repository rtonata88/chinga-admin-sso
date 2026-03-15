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
        Schema::create('venue_shifts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('venue_id')->constrained()->onDelete('cascade');
            $table->foreignId('staff_id')->constrained('venue_staff')->onDelete('cascade');
            $table->foreignId('terminal_id')
                ->nullable()
                ->constrained('venue_terminals')
                ->onDelete('set null');
            $table->decimal('opening_balance', 15, 2);
            $table->decimal('closing_balance', 15, 2)->nullable();
            $table->decimal('total_loads', 15, 2)->default(0.00);
            $table->decimal('total_cashouts', 15, 2)->default(0.00);
            $table->integer('codes_created')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['venue_id', 'started_at']);
            $table->index(['staff_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venue_shifts');
    }
};

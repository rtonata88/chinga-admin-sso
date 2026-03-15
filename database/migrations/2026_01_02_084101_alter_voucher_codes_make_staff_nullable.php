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
        Schema::table('voucher_codes', function (Blueprint $table) {
            // Make created_by_staff_id nullable for admin-generated codes
            $table->foreignId('created_by_staff_id')
                ->nullable()
                ->change();

            // Add created_by_admin_id for tracking admin-generated codes
            $table->foreignId('created_by_admin_id')
                ->nullable()
                ->after('created_by_staff_id')
                ->constrained('users')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('voucher_codes', function (Blueprint $table) {
            $table->dropForeign(['created_by_admin_id']);
            $table->dropColumn('created_by_admin_id');
        });
    }
};

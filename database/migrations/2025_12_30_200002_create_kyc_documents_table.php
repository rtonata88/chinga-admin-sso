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
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('document_type', [
                'passport',
                'national_id',
                'drivers_license',
                'proof_of_address',
                'selfie',
                'source_of_funds',
            ]);
            $table->string('file_path', 500);
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');
            $table->timestamp('verified_at')->nullable();
            $table->date('document_expiry')->nullable();
            $table->string('document_number')->nullable();
            $table->string('issuing_country', 2)->nullable();
            $table->json('metadata')->nullable();
            $table->json('extracted_data')->nullable(); // For OCR/AI extracted data
            $table->timestamps();

            $table->index(['user_id', 'document_type']);
            $table->index(['user_id', 'status']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');
    }
};

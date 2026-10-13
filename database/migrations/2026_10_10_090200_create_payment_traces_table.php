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
        Schema::create('payment_traces', function (Blueprint $table) {
            $table->id();

            // Null for calls that belong to no one payment — a health check, an
            // access token, a plan sync.
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();

            $table->string('vendor', 100)->index(); // PaymentVendorEnum
            $table->string('trace_step', 50)->index(); // PaymentTraceStepEnum

            $table->string('method', 10)->nullable();
            $table->string('endpoint', 500)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('succeeded')->default(false)->index();
            $table->string('message', 500)->nullable();

            // Request and response bodies with every credential and card field
            // replaced, before they are written. See PaymentTraceService::redact().
            $table->json('context')->nullable();

            $table->timestamp('created_at')->useCurrent()->index();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_traces');
    }
};

<?php

use App\Enums\PaymentVendorEnum;
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
        Schema::create('transaction_gateways', function (Blueprint $table) {
            $table->id();

            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();

            $table->string('vendor', 100)->default(PaymentVendorEnum::MANUAL)->index();

            // The processor's own identifiers. Indexed because the only thing a
            // webhook arrives holding is one of these, and it has to find its row
            // before it can do anything else.
            $table->string('gateway_id')->nullable()->index();
            $table->string('gateway_request_id')->nullable()->index();
            $table->string('gateway_url')->nullable();

            $table->timestamp('expires_at')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_gateways');
    }
};

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

            // What the vendor was asked to charge in. The ledger is written in the
            // site default, and the verification compares against this, so a
            // payment settled in a different currency is caught rather than
            // credited at face value.
            $table->string('currency', 10)->nullable();

            // The processor's own identifiers. Indexed because the only thing a
            // webhook arrives holding is one of these, and it has to find its row
            // before it can do anything else.
            $table->string('gateway_id')->nullable()->index();
            $table->string('gateway_request_id')->nullable()->index();
            $table->string('gateway_url')->nullable();

            // How the customer paid — card, bank transfer, USSD — as the vendor
            // reported it. Display only.
            $table->string('channel', 100)->nullable();

            // Why a checkout was not settled automatically: an amount or currency
            // that did not match, or the vendor's own failure message.
            $table->string('failure_reason')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('verified_at')->nullable();

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

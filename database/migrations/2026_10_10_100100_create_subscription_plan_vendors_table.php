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
        Schema::create('subscription_plan_vendors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->string('vendor', 100); // PaymentVendorEnum

            // The plan as the vendor knows it — a Paystack plan_code, a Stripe
            // price, a PayPal plan, a Flutterwave payment plan id — and the
            // product it hangs off where the vendor has one.
            $table->string('vendor_plan_id', 191);
            $table->string('vendor_product_id', 191)->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // One copy of a plan per vendor. A price change empties vendor_plan_id
            // and keeps the product; the next checkout creates a fresh price.
            $table->unique(['subscription_plan_id', 'vendor']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_vendors');
    }
};

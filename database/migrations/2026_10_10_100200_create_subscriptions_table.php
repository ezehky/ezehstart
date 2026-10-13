<?php

use App\Enums\StatusSubscription;
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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Restricted: a plan somebody is subscribed to cannot be deleted out
            // from under them. Deactivate it instead.
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();

            $table->string('vendor', 100)->index(); // PaymentVendorEnum
            $table->string('vendor_subscription_id', 191)->nullable()->index();
            $table->string('vendor_customer_id', 191)->nullable();

            // The vendor plan this was started on. The plan's own vendor copy is
            // replaced when its price changes; this one is what was signed up to.
            $table->string('vendor_plan_id', 191)->nullable();

            // Paystack's email_token, needed to disable a subscription. Encrypted
            // at rest by the model cast.
            $table->text('vendor_token')->nullable();

            // What was agreed at the time, so a later price change on the plan
            // never rewrites what an existing subscriber was told they would pay.
            $table->unsignedBigInteger('amount');
            $table->string('currency', 10);
            $table->string('billing_interval', 20);

            $table->tinyInteger('status')->default(StatusSubscription::PENDING)->index();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

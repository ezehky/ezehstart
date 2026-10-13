<?php

use App\Enums\PaymentWebhookTopicEnum;
use App\Enums\StatusPaymentWebhook;
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
        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();

            $table->string('vendor', 100)->index(); // PaymentVendorEnum

            // The vendor's own event id where it sends one (Stripe, PayPal), or a
            // hash of the body where it does not. Unique with the vendor, so a
            // retried delivery is refused by the database rather than worked twice.
            $table->string('event_key', 191);

            $table->string('event_name', 191)->nullable();
            $table->string('topic', 50)->default(PaymentWebhookTopicEnum::OTHER)->index();

            // Our transaction reference, or the vendor's id where the payload
            // carries no reference of ours. Indexed for the transaction view.
            $table->string('reference', 200)->nullable()->index();

            $table->json('payload');

            $table->tinyInteger('status')->default(StatusPaymentWebhook::PENDING)->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['vendor', 'event_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_webhooks');
    }
};

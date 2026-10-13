<?php

use App\Enums\StatusDefault;
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
        // A plan's price for each interval it is offered on — monthly, yearly and
        // so on, each its own amount. What a member signs up to is one of these.
        Schema::create('subscription_plan_prices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->string('billing_interval', 20); // SubscriptionIntervalEnum

            // Minor units, read through MoneyCast, in the site default currency.
            $table->unsignedBigInteger('amount');
            $table->string('currency', 10);

            // An interval taken off a plan is switched off, never deleted:
            // subscribers on it still point here.
            $table->tinyInteger('status')->default(StatusDefault::ACTIVE)->index();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['subscription_plan_id', 'billing_interval']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_prices');
    }
};

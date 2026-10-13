<?php

use App\Enums\StatusDefault;
use App\Enums\SubscriptionIntervalEnum;
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
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);
            $table->string('slug', 191)->unique();
            $table->text('description')->nullable();

            // What a member sees listed, one line each.
            $table->json('features')->nullable();

            // Minor units, read through MoneyCast, in the site default currency.
            $table->unsignedBigInteger('amount');
            $table->string('currency', 10);
            $table->string('billing_interval', 20)->default(SubscriptionIntervalEnum::MONTHLY);

            $table->tinyInteger('status')->default(StatusDefault::ACTIVE)->index();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};

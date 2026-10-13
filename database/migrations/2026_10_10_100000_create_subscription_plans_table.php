<?php

use App\Enums\StatusDefault;
use App\Enums\StatusYes;
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

            // Prices live in subscription_plan_prices, one per interval, and
            // features in subscription_plan_features, one row per feature granted.

            // What an account with no subscription has. At most one plan is free,
            // it has no prices, and nobody subscribes to it.
            $table->boolean('is_free')->default(StatusYes::NO);

            // Singled out on the pricing cards. At most one at a time.
            $table->boolean('is_recommended')->default(StatusYes::NO);

            // Days of card-less trial an account may take once, 0 for none.
            $table->unsignedSmallInteger('trial_days')->default(0);

            // "Everything in Basic, plus": this plan grants all the included
            // plan grants, and its own rows on top. Restricted, so a plan others
            // build on cannot be deleted out from under them.
            $table->foreignId('includes_plan_id')->nullable()->constrained('subscription_plans')->restrictOnDelete();

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

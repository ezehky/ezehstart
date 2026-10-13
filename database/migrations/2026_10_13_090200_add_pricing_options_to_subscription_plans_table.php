<?php

use App\Enums\StatusDefault;
use App\Enums\StatusYes;
use App\Enums\SubscriptionIntervalEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            // What an account with no subscription has. At most one plan is free,
            // it has no prices, and nobody subscribes to it.
            $table->boolean('is_free')->default(StatusYes::NO)->after('description');

            // Singled out on the pricing cards. At most one at a time.
            $table->boolean('is_recommended')->default(StatusYes::NO)->after('is_free');

            // Days of card-less trial an account may take once, 0 for none.
            $table->unsignedSmallInteger('trial_days')->default(0)->after('is_recommended');

            // "Everything in Basic, plus": this plan grants all the included
            // plan grants, and its own rows on top. Restricted, so a plan others
            // build on cannot be deleted out from under them.
            $table->foreignId('includes_plan_id')->nullable()->after('trial_days')->constrained('subscription_plans')->restrictOnDelete();
        });

        // The single price each plan had becomes its first row in the price table.
        DB::table('subscription_plans')->orderBy('id')->each(function (object $plan) {
            DB::table('subscription_plan_prices')->insert([
                'subscription_plan_id' => $plan->id,
                'billing_interval' => $plan->billing_interval,
                'amount' => $plan->amount,
                'currency' => $plan->currency,
                'status' => StatusDefault::ACTIVE,
            ]);
        });

        // The free-text feature lines go: a feature is now a SubscriptionFeatureEnum
        // row a plan grants, which the old lines cannot be read into.
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['features', 'amount', 'currency', 'billing_interval']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->json('features')->nullable()->after('description');
            $table->unsignedBigInteger('amount')->default(0)->after('features');
            $table->string('currency', 10)->default('')->after('amount');
            $table->string('billing_interval', 20)->default(SubscriptionIntervalEnum::MONTHLY)->after('currency');
        });

        // Back to one price a plan: the cheapest it was offered at.
        DB::table('subscription_plan_prices')->orderBy('amount')->get()->unique('subscription_plan_id')
            ->each(fn (object $price) => DB::table('subscription_plans')->where('id', $price->subscription_plan_id)->update([
                'amount' => $price->amount,
                'currency' => $price->currency,
                'billing_interval' => $price->billing_interval,
            ]));

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('includes_plan_id');
            $table->dropColumn(['is_free', 'is_recommended', 'trial_days']);
        });
    }
};

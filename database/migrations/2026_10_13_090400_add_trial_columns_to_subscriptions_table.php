<?php

use App\Enums\StatusSubscription;
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
        Schema::table('subscriptions', function (Blueprint $table) {
            // The price signed up to. Null on a trial, which is on a plan but no
            // price. Restricted for the same reason the plan is.
            $table->foreignId('subscription_plan_price_id')->nullable()->after('subscription_plan_id')->constrained()->restrictOnDelete();

            // A trial is run by the kit, not a vendor, and bills nothing — so it
            // has no vendor and no interval.
            $table->string('vendor', 100)->nullable()->change();
            $table->string('billing_interval', 20)->nullable()->change();
        });

        // Point every existing subscription at the price its plan and interval
        // now live under.
        DB::table('subscription_plan_prices')->orderBy('id')->each(function (object $price) {
            DB::table('subscriptions')
                ->where('subscription_plan_id', $price->subscription_plan_id)
                ->where('billing_interval', $price->billing_interval)
                ->update(['subscription_plan_price_id' => $price->id]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Trials cannot be written without a vendor.
        DB::table('subscriptions')->where('status', StatusSubscription::TRIALING)->delete();

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_plan_price_id');
            $table->string('vendor', 100)->nullable(false)->change();
            $table->string('billing_interval', 20)->nullable(false)->change();
        });
    }
};

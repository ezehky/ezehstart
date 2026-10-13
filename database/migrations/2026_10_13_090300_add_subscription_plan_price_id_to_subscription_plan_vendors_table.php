<?php

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
        // A vendor plan is a price now — the monthly and the yearly are two plans
        // on Paystack, two prices on Stripe. The copies already made were for the
        // plan's single price; they are dropped rather than guessed at, and the
        // next checkout through each vendor makes them again. Subscribers keep
        // their own subscriptions.vendor_plan_id, so nobody is affected.
        DB::table('subscription_plan_vendors')->delete();

        Schema::table('subscription_plan_vendors', function (Blueprint $table) {
            $table->foreignId('subscription_plan_price_id')->after('subscription_plan_id')->constrained()->cascadeOnDelete();

            // MySQL may be using the old unique index to serve the plan foreign
            // key; give that key an index of its own before the unique goes.
            $table->index('subscription_plan_id');
        });

        Schema::table('subscription_plan_vendors', function (Blueprint $table) {
            $table->dropUnique(['subscription_plan_id', 'vendor']);
            $table->unique(['subscription_plan_price_id', 'vendor']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('subscription_plan_vendors')->delete();

        Schema::table('subscription_plan_vendors', function (Blueprint $table) {
            $table->dropUnique(['subscription_plan_price_id', 'vendor']);
            $table->unique(['subscription_plan_id', 'vendor']);
        });

        Schema::table('subscription_plan_vendors', function (Blueprint $table) {
            $table->dropIndex(['subscription_plan_id']);
            $table->dropConstrainedForeignId('subscription_plan_price_id');
        });
    }
};

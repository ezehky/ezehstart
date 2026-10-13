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
        Schema::table('transaction_gateways', function (Blueprint $table) {
            // What the vendor was asked to charge in. The ledger is written in the
            // site default, and the verification compares against this, so a
            // payment settled in a different currency is caught rather than
            // credited at face value.
            $table->string('currency', 10)->nullable()->after('vendor');

            // How the customer paid — card, bank transfer, USSD — as the vendor
            // reported it. Display only.
            $table->string('channel', 100)->nullable()->after('gateway_url');

            // Why a checkout was not settled automatically: an amount or currency
            // that did not match, or the vendor's own failure message.
            $table->string('failure_reason')->nullable()->after('channel');

            $table->timestamp('paid_at')->nullable()->after('expires_at');
            $table->timestamp('verified_at')->nullable()->after('paid_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transaction_gateways', function (Blueprint $table) {
            $table->dropColumn(['currency', 'channel', 'failure_reason', 'paid_at', 'verified_at']);
        });
    }
};

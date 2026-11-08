<?php

use App\Enums\StatusDefault;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Dated ahead of transaction_gateways, which points at it.
     */
    public function up(): void
    {
        Schema::create('payment_subaccounts', function (Blueprint $table) {
            $table->id();

            // A subaccount lives on one vendor. A payee paid through two vendors
            // is two rows, because each vendor knows its banks by its own codes.
            $table->string('vendor', 100)->index(); // PaymentVendorEnum

            $table->string('business_name', 150);
            $table->string('description')->nullable();

            // The subaccount as the vendor knows it: the code a checkout names
            // (a Paystack ACCT_, a Flutterwave RS_, a Monnify MFY_SUB_), and the
            // record id an update is addressed to where that differs.
            $table->string('subaccount_code', 191);
            $table->string('vendor_record_id', 191)->nullable();

            // Where the vendor settles the subaccount's share. The account name
            // is the vendor's answer, not something typed in.
            $table->string('bank_code', 50);
            $table->string('bank_name', 150)->nullable();
            $table->string('account_number', 30);
            $table->string('account_name', 150)->nullable();

            $table->string('contact_email', 190)->nullable();
            $table->string('contact_phone', 30)->nullable();

            // The ledger's currency when it was created. A checkout in any other
            // currency is refused rather than split into an account that cannot
            // take it.
            $table->string('currency', 10);

            // What the site keeps of each payment, as a percentage. The rest is
            // settled to the subaccount. Monnify is told the complement.
            $table->decimal('platform_percentage', 5, 2)->default(0);

            $table->tinyInteger('status')->default(StatusDefault::ACTIVE)->index();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['vendor', 'subaccount_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_subaccounts');
    }
};

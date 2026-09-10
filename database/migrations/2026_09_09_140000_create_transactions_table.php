<?php

use App\Enums\StatusTransaction;
use App\Enums\TransactionTypeEnum;
use App\Enums\TransactionViaEnum;
use App\Enums\TransactionWalletEnum;
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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The public identifier, from kReferenceId(). Route binding uses it so
            // a receipt URL never leaks how many transactions the platform has
            // processed, and support can read one down a phone line.
            $table->string('reference', 200)->unique();

            $table->string('transaction_type', 20)->default(TransactionTypeEnum::CREDIT)->index();
            $table->string('transaction_group', 100)->index(); // TransactionGroupEnum
            $table->string('transaction_wallet', 50)->default(TransactionWalletEnum::BALANCE)->index();

            // Minor units, read through MoneyCast. A raw sum() over this column is
            // in kobo/cents and has to be divided before it is shown to anybody.
            $table->unsignedBigInteger('amount');

            $table->string('description');
            $table->string('via', 50)->default(TransactionViaEnum::PLATFORM)->index();

            // Whatever the money was against — an order, a subscription, a payout
            // request. Nullable because a deposit is against nothing but itself.
            $table->nullableMorphs('transactionable');

            $table->tinyInteger('status')->default(StatusTransaction::CONFIRMED)->index();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Every statement, receipt list and export is one user's rows in date
            // order; the admin queue is the same query without the user.
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

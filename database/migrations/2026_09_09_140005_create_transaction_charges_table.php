<?php

use App\Enums\TransactionChargeEnum;
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
        Schema::create('transaction_charges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();

            $table->string('charge_type', 20)->default(TransactionChargeEnum::FEE)->index();

            // Minor units, read through MoneyCast.
            $table->unsignedBigInteger('amount');

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // One row per kind of charge per transaction — a fee and a tax, not two
            // fees that nobody can explain to a customer.
            $table->unique(['transaction_id', 'charge_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_charges');
    }
};

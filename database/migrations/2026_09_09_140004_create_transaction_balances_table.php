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
        // The balance either side of the movement, captured under the same lock
        // that wrote it. Recomputing a historical balance by replaying the ledger
        // gives you today's answer to yesterday's question; this gives you the
        // number the user actually saw.
        Schema::create('transaction_balances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();

            // Minor units, like every other money column, read through MoneyCast.
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // One snapshot per transaction. A second row would mean the movement
            // was applied twice, and the index makes that impossible rather than
            // merely unlikely.
            $table->unique('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_balances');
    }
};

<?php

use App\Enums\CurrencySymbolPositionEnum;
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
        Schema::table('currencies', function (Blueprint $table) {
            // Before, for every row that predates this: kMoneyFormat() printed the
            // symbol in front of every amount until now, so nothing already on
            // screen moves.
            $table->string('symbol_position', 10)->default(CurrencySymbolPositionEnum::BEFORE)->after('symbol');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn('symbol_position');
        });
    }
};

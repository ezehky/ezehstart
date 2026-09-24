<?php

use App\Models\Currency;
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
        // The rate moves from a decimal to a whole number of hundred-millionths,
        // read back through MoneyCast like every amount in the ledger. Eight places,
        // the same the decimal kept, so no rate loses anything on the way over.
        Schema::table('currencies', function (Blueprint $table) {
            $table->unsignedBigInteger('rate_units')->default(Currency::RATE_SCALE)->after('symbol_position');
        });

        // Row by row in PHP rather than one UPDATE: there are a handful of rows, and
        // this reads the same on every database the kit runs on.
        DB::table('currencies')->orderBy('id')->each(function (object $row) {
            DB::table('currencies')->where('id', $row->id)->update([
                'rate_units' => (int) round((float) $row->rate * Currency::RATE_SCALE),
            ]);
        });

        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn('rate');
        });

        Schema::table('currencies', function (Blueprint $table) {
            $table->renameColumn('rate_units', 'rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->decimal('rate_decimal', 20, 8)->default(1)->after('symbol_position');
        });

        DB::table('currencies')->orderBy('id')->each(function (object $row) {
            DB::table('currencies')->where('id', $row->id)->update([
                'rate_decimal' => $row->rate / Currency::RATE_SCALE,
            ]);
        });

        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn('rate');
        });

        Schema::table('currencies', function (Blueprint $table) {
            $table->renameColumn('rate_decimal', 'rate');
        });
    }
};

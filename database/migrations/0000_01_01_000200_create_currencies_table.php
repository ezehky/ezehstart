<?php

use App\Enums\StatusDefault;
use App\Enums\StatusYes;
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
        // Ahead of the users table on purpose: an account points at the currency
        // it reads amounts in, so this has to exist before that foreign key does.
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);

            // The ISO 4217 code. Unique, and what CurrencySeeder upserts on —
            // reseeding has to update the naira, not add a second one.
            $table->string('code', 3)->unique();

            // Stored as it is typed, an entity or the glyph itself. kMoneyFormat()
            // decodes on the way out when it is not rendering HTML.
            $table->string('symbol', 20);

            // How many of this currency one unit of the default buys. The default
            // is always 1, and changing the default rebases every other row so
            // that stays true — see CurrencyService::makeDefault().
            //
            // Replaced by a whole number of hundred-millionths in
            // add_integer_rate_to_currencies_table, read back through MoneyCast.
            $table->decimal('rate', 20, 8)->default(1);

            // Exactly one row carries this. The ledger is written in the default,
            // so it is the currency every stored amount is actually in.
            $table->boolean('is_default')->default(StatusYes::NO)->index();

            $table->boolean('status')->default(StatusDefault::ACTIVE)->index();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};

<?php

use App\Enums\StatusDefault;
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
        // Deliberately placed with the framework tables rather than the domain
        // ones: countries are reference data that profiles, addresses and phone
        // inputs all reach for, so nothing that comes later should have to wonder
        // whether the table is there yet.
        Schema::create('countries', function (Blueprint $table) {
            $table->id();

            $table->string('name')->index();

            // Unique, and the key CountrySeeder upserts on — refreshing the list
            // has to update Nigeria, not insert a second one.
            $table->string('iso2', 2)->unique();

            $table->string('iso3', 3)->nullable();

            // Kept as a string: dialling codes are written with a leading plus and
            // some are not numeric once you include the variants operators use.
            $table->string('phone_code', 10)->nullable();

            $table->string('currency', 10)->nullable();
            $table->string('currency_symbol', 10)->nullable();

            // The emoji flag, not a file path. It renders everywhere and costs no
            // request, which a 250-image sprite sheet cannot say for itself.
            $table->string('flag', 20)->nullable();

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
        Schema::dropIfExists('countries');
    }
};

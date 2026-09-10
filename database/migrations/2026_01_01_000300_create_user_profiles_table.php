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
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Null on delete rather than cascade: removing a country from the
            // reference list must not take somebody's profile with it, and the
            // city they typed stays true either way.
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();

            $table->string('gender', 10)->nullable(); // GenderEnum

            $table->string('city')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->text('bio')->nullable();

            $table->json('settings')->nullable(); // UserService::profileDefaultSettings()

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};

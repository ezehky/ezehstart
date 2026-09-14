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

            // What this person does, for the byline under a post — "Staff writer",
            // "Founder". One line rather than a second bio: the author card has room
            // for a title beside the name and nothing more.
            $table->string('work')->nullable();

            // Where this person can be found, as platform => handle or URL, keyed by
            // SocialHandleEnum. A column rather than a table because it is a handful
            // of strings read all at once and never queried across accounts — the same
            // reasoning as the gate maps. The author byline on a post is what reads it.
            $table->json('socials')->nullable();

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

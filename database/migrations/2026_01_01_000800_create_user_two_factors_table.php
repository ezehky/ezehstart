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
        // A table of its own rather than columns on users: the secret and the
        // recovery codes are the most sensitive rows in the schema, and keeping
        // them out of the model every request loads means they are not sitting in
        // memory, in a cache entry, or in a log dump for the whole session.
        Schema::create('user_two_factors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Encrypted by the model cast. Nullable because the row is created
            // when enrolment starts and only filled once the first code verifies.
            $table->text('secret')->nullable();
            $table->text('recovery_codes')->nullable();

            // "Remember this device for 30 days" — the token is hashed, so a
            // stolen database still does not hand anybody a second factor.
            $table->string('remember_token', 100)->nullable()->index();
            $table->timestamp('remember_expires_at')->nullable();

            // Regenerating recovery codes invalidates the previous set, and people
            // do lose them. The count is here so support can see how often.
            $table->unsignedTinyInteger('recovery_generated_count')->default(0);

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();

            $table->boolean('status')->default(StatusDefault::INACTIVE);

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_two_factors');
    }
};

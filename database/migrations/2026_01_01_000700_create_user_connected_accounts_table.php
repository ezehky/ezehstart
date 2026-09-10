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
        Schema::create('user_connected_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 50)->index(); // SocialProviderEnum
            $table->string('provider_id', 100);

            $table->string('nickname')->nullable();
            $table->string('avatar')->nullable();

            // Tokens are stored so an account can be re-verified or revoked later.
            // They are encrypted by the model cast, never by the column type — a
            // text column keeps the ciphertext from being silently truncated.
            $table->text('provider_token')->nullable();
            $table->text('provider_refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            $table->boolean('status')->default(StatusDefault::ACTIVE);

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // One identity per provider, and one link per user per provider. The
            // first constraint is what stops two local accounts claiming the same
            // Google login and turning sign-in into a coin toss.
            $table->unique(['provider', 'provider_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_connected_accounts');
    }
};

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
        // The shape spatie/laravel-passkeys reads and writes. Its own stub is not
        // published: the column names are the package's contract, but the
        // timestamps follow the house pair and the foreign key follows the users
        // table the way every other account-owned table here does.
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();

            // Named by the package, not by us — Spatie\LaravelPasskeys reaches the
            // owner through authenticatable_id, so the column cannot be user_id.
            $table->foreignId('authenticatable_id')->constrained('users')->cascadeOnDelete();

            // What the account holder called the device, so the list on the
            // security screen says "Work laptop" rather than a key fingerprint.
            $table->string('name');

            // Text rather than a string: a credential id is binary the package
            // encodes, and some authenticators hand back ids longer than 255.
            $table->text('credential_id');

            // The serialised public key credential source. Only the public half of
            // the key pair ever reaches the server, so there is nothing secret here
            // — a leaked row signs nobody in.
            $table->json('data');

            $table->timestamp('last_used_at')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};

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
        // Append-only, so created_at alone. A row here is a hash somebody used to
        // be able to sign in with; it is never edited, only compared against and
        // eventually pruned once it falls outside the configured depth.
        Schema::create('password_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('password');

            $table->timestamp('created_at')->useCurrent();

            // Every reuse check is "this user's last N hashes, newest first", and
            // that is the only query this table ever answers.
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_histories');
    }
};

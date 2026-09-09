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
        Schema::create('user_consents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Consent is evidence, so a policy somebody accepted must not be
            // deletable underneath it. This deliberately restricts rather than
            // cascades — the delete should fail loudly instead of quietly taking
            // the record of what was agreed with it.
            $table->foreignId('policy_id')->constrained()->restrictOnDelete();

            $table->timestamp('accepted_at')->useCurrent();

            // Recorded the same way as an activity log, so an acceptance can be
            // evidenced if it is ever questioned.
            $table->ipAddress();
            $table->string('user_agent', 500)->nullable();

            // A user consents to a given version once. A new version is a new row.
            $table->unique(['user_id', 'policy_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_consents');
    }
};

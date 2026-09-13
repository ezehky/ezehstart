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
        // One row per warning actually sent about a pending deletion. The table
        // exists for the unique index: two overlapping scheduler ticks both try
        // to insert, and only one of them can win, so nobody is told twice that
        // their account is about to go.
        Schema::create('user_deletion_reminders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // DeletionReminderEnum — the lead time this row claims.
            $table->string('reminder', 20);

            $table->timestamp('sent_at')->useCurrent();

            // The claim. Restoring an account clears its rows, so somebody who
            // changes their mind twice is warned properly the second time.
            $table->unique(['user_id', 'reminder']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_deletion_reminders');
    }
};

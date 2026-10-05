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
        Schema::table('users', function (Blueprint $table) {
            // When the "verify or lose it" warning went out. The sweep counts its
            // grace period from here rather than from sign-up, so an account that
            // was already old the first time the sweep saw it still gets the full
            // window between the warning and the delete.
            $table->timestamp('unverified_notice_sent_at')->nullable()->after('email_verified_at');

            // When the last "we missed you" went out. Another is only sent once the
            // account has been seen again since, so an account that never comes
            // back is mailed once rather than on every run forever.
            $table->timestamp('inactivity_reminder_sent_at')->nullable()->after('last_seen_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['unverified_notice_sent_at', 'inactivity_reminder_sent_at']);
        });
    }
};

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
        Schema::table('announcements', function (Blueprint $table) {
            // An icon from the set the icon picker offers, drawn large on a tinted
            // panel where the picture would sit. One or the other: the admin screen
            // clears whichever was not chosen, and a picture wins if both survive.
            $table->string('icon', 60)->nullable()->after('image_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};

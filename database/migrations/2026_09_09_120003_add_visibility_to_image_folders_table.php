<?php

use App\Enums\ImageVisibilityEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('image_folders', function (Blueprint $table) {
            // Who may browse the folder. This gates the folder, not the images
            // inside it: an image keeps the visibility it was given, so refiling
            // it never silently changes who can see it. The folder's setting is
            // what a new upload starts from, and nothing more.
            $table->string('visibility', 20)->default(ImageVisibilityEnum::PRIVATE)->index()->after('slug');

            // Only read when visibility is ROLE. Any other case must leave it null,
            // so changing visibility twice cannot quietly restore an old audience.
            $table->string('visible_to_role', 20)->nullable()->index()->after('visibility'); // UserRoleEnum
        });

        // Folders that predate this column are the platform's shared ones, and
        // those were browsable by everybody before the column existed. Defaulting
        // them to private would hide folders people are already using.
        DB::table('image_folders')
            ->whereNull('user_id')
            ->update(['visibility' => ImageVisibilityEnum::PUBLIC]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('image_folders', function (Blueprint $table) {
            $table->dropColumn(['visibility', 'visible_to_role']);
        });
    }
};

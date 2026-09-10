<?php

use App\Enums\MediaVisibilityEnum;
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
        // A video row is a reference, not a file. Nothing is uploaded and nothing
        // is stored on a disk, which is what makes this table so much narrower
        // than images — no path, no mime type, no size, no optimiser.
        Schema::create('videos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // A folder is a label, so losing one must not lose the video. It falls
            // back to the root of its owner's library.
            $table->foreignId('video_folder_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title')->index();

            $table->string('provider', 20)->index(); // VideoProviderEnum

            // The provider's own id for the video, never a URL. Storing the id and
            // rebuilding the player URL from it is what makes the allowlist hold:
            // there is no author-supplied URL left in the row to be trusted later.
            $table->string('video_id', 64);

            $table->text('description')->nullable();

            // Seconds, filled in only where somebody typed it. Nothing here calls
            // out to a provider API, so an unknown duration stays unknown.
            $table->unsignedInteger('duration')->nullable();

            $table->string('visibility', 20)->default(MediaVisibilityEnum::PRIVATE)->index();

            // Only read when visibility is ROLE. Any other case must leave it null,
            // so changing visibility twice cannot quietly restore an old audience.
            $table->string('visible_to_role', 20)->nullable()->index(); // UserRoleEnum

            $table->boolean('status')->default(StatusDefault::ACTIVE)->index();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // One person adding the same video twice is a mistake, not an intent —
            // but two people may each want their own row for the same video, with
            // their own title and their own visibility, so the key includes the
            // owner rather than being global.
            $table->unique(['user_id', 'provider', 'video_id']);

            // The library's default view is "my videos, newest first", and the
            // picker filters the same set by folder.
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};

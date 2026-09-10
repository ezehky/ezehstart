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
        Schema::create('images', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // A folder is a label, so losing one must not lose the file. The image
            // falls back to the root of its owner's library.
            $table->foreignId('image_folder_id')->nullable()->constrained()->nullOnDelete();

            // The display name, and the only part of an image anybody may rename.
            $table->string('title')->index();

            // The stored path, written once at upload and never touched again.
            // Renaming it would break every page, post and email already pointing
            // at the old URL, which is exactly why the two are separate columns.
            $table->string('file_path')->unique();

            $table->string('disk', 20)->default('public');

            $table->string('mime_type', 100);
            $table->string('extension', 10);

            // Bytes, not kilobytes — the upload limit is expressed in kilobytes for
            // people and converted once, rather than rounded at every comparison.
            $table->unsignedBigInteger('size');

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->string('alt_text', 500)->nullable();

            $table->string('visibility', 20)->default(MediaVisibilityEnum::PRIVATE)->index();

            // Only read when visibility is ROLE. Any other case must leave it null,
            // so changing visibility twice cannot quietly restore an old audience.
            $table->string('visible_to_type', 20)->nullable()->index(); // UserTypeEnum

            $table->boolean('status')->default(StatusDefault::ACTIVE)->index();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // The library's default view is "my images, newest first", and the
            // picker filters the same set by folder.
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};

<?php

use App\Enums\StatusPost;
use App\Enums\StatusYes;
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
        Schema::create('posts', function (Blueprint $table) {
            $table->id();

            // The author outlives their posts in every sense that matters here: an
            // account removal should not silently unpublish the archive, so this
            // nulls and the byline falls back to the site name.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('image_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title')->index();
            $table->string('slug')->unique();

            $table->string('excerpt', 500);

            // Tiptap writes HTML, not markdown, so this is stored as authored and
            // sanitised on the way in rather than compiled on the way out.
            $table->longText('content');

            // Computed once on save from the word count. Recomputing it on every
            // listing render is a lot of work to tell somebody "4 min read".
            $table->unsignedInteger('read_minutes')->default(0);
            $table->unsignedBigInteger('views')->default(0);

            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();

            // Set when the post first reaches PUBLISHED and left alone afterwards,
            // so editing a typo three months later does not move it back to the
            // top of the feed.
            $table->timestamp('published_at')->nullable()->index();

            // When subscribers were told about this post. The stamp is the claim:
            // a post unpublished and published again is not news twice, and two
            // overlapping scheduler ticks cannot both announce it.
            $table->timestamp('announced_at')->nullable();

            $table->boolean('is_featured')->default(StatusYes::NO)->index();
            $table->tinyInteger('status')->default(StatusPost::DRAFT)->index();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};

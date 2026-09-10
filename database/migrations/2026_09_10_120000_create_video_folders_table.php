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
        // Deliberately a second table rather than a shared media_folders one. The
        // two libraries are browsed separately and a folder called "banners" in
        // the image library has nothing to say about videos; merging them would
        // mean every query in both carrying a type filter it never wants to drop.
        Schema::create('video_folders', function (Blueprint $table) {
            $table->id();

            // Null means a folder the system owns and everybody browses. A folder
            // belonging to a user disappears with them; their videos do not, which
            // is why videos point at the folder rather than the other way round.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            // Deleting a parent promotes its children to the root instead of
            // taking the subtree with it. Losing a tree because somebody tidied
            // one level of it is not a recoverable mistake, and MySQL does not
            // cascade reliably through a self-reference anyway.
            $table->foreignId('parent_id')->nullable()->constrained('video_folders')->nullOnDelete();

            $table->string('name')->index();
            $table->string('slug');

            $table->string('visibility', 20)->default(MediaVisibilityEnum::PRIVATE)->index();

            // Only read when visibility is ROLE. Any other case must leave it null,
            // so changing visibility twice cannot quietly restore an old audience.
            $table->string('visible_to_role', 20)->nullable()->index(); // UserRoleEnum

            $table->boolean('status')->default(StatusDefault::ACTIVE)->index();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Names are unique among siblings, not globally: two people may both
            // want a folder called "tutorials", and so may two levels of one tree.
            $table->unique(['user_id', 'parent_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_folders');
    }
};

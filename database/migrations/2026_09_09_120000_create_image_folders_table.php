<?php

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
        Schema::create('image_folders', function (Blueprint $table) {
            $table->id();

            // Null means a folder the system owns and everybody browses. A folder
            // belonging to a user disappears with them; their images do not, which
            // is why images point at the folder rather than the other way round.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            // Deleting a parent promotes its children to the root instead of
            // taking the subtree with it. Losing a tree because somebody tidied
            // one level of it is not a recoverable mistake, and MySQL does not
            // cascade reliably through a self-reference anyway.
            $table->foreignId('parent_id')->nullable()->constrained('image_folders')->nullOnDelete();

            $table->string('name')->index();
            $table->string('slug');

            $table->boolean('status')->default(StatusDefault::ACTIVE)->index();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Names are unique among siblings, not globally: two people may both
            // want a folder called "banners", and so may two levels of one tree.
            $table->unique(['user_id', 'parent_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('image_folders');
    }
};

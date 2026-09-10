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
        // One table for every kind of category, separated by category_group. A
        // second products table would need its own categories, its own admin
        // screen and its own tree code, all of which would be this file again.
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            // Sub-categories promote to the root when their parent goes, for the
            // same reason folders do: a tidy-up should not delete content.
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->foreignId('image_id')->nullable()->constrained()->nullOnDelete();

            $table->string('category_group', 30)->index(); // CategoryGroupEnum

            $table->string('name')->index();
            $table->string('slug');

            $table->text('description')->nullable();

            $table->unsignedInteger('flow_order')->default(0);
            $table->boolean('status')->default(StatusDefault::ACTIVE)->index();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Unique per group rather than globally: "skincare" may legitimately be
            // both a blog category and a product category, and they are not the
            // same row.
            $table->unique(['category_group', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};

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
        // "Delete only images nobody is using" is a rule that cannot be answered by
        // looking at the images table, and scanning every model that might embed
        // one does not scale past the second such model. Attachments are recorded
        // here instead, so the question is one indexed query.
        Schema::create('image_usages', function (Blueprint $table) {
            $table->id();

            // Restrict rather than cascade: this row is the whole reason the
            // delete should fail. An attached image must refuse to go, loudly,
            // instead of leaving a broken picture behind on a published page.
            $table->foreignId('image_id')->constrained()->restrictOnDelete();

            $table->morphs('usable');

            // Which attribute of the record holds it — a cover image and an image
            // embedded in the body are different usages of the same file, and
            // detaching one must not release the other.
            $table->string('field', 50)->default('image');

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['image_id', 'usable_type', 'usable_id', 'field']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('image_usages');
    }
};

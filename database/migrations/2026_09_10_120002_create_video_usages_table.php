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
        // The same bargain image_usages makes: "delete only videos nobody is using"
        // cannot be answered from the videos table, and scanning every model that
        // might embed one does not scale past the second such model. Attachments
        // are recorded here instead, so the question is one indexed query.
        Schema::create('video_usages', function (Blueprint $table) {
            $table->id();

            // Restrict rather than cascade: this row is the whole reason the
            // delete should fail. An attached video must refuse to go, loudly,
            // instead of leaving an empty frame on a published page.
            $table->foreignId('video_id')->constrained()->restrictOnDelete();

            $table->morphs('usable');

            // Which attribute of the record holds it — a trailer on a post and a
            // video embedded in its body are different usages of the same row, and
            // detaching one must not release the other.
            $table->string('field', 50)->default('video');

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['video_id', 'usable_type', 'usable_id', 'field']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_usages');
    }
};

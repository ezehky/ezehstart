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
        // A pivot row is never edited, only made and unmade, so created_at alone.
        Schema::create('categorizables', function (Blueprint $table) {
            $table->id();

            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            $table->morphs('categorizable');

            $table->timestamp('created_at')->useCurrent();

            // Attaching the same category twice is a double-submitted form, not a
            // second categorisation. The index is what makes it a no-op.
            $table->unique(['category_id', 'categorizable_type', 'categorizable_id'], 'categorizables_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categorizables');
    }
};

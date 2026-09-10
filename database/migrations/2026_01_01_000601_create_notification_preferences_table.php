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
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Retiring a type takes its preference rows with it. Keeping orphaned
            // switches for something nobody can send would only leave the settings
            // page rendering options that do nothing.
            $table->foreignId('notification_type_id')->constrained()->cascadeOnDelete();

            $table->boolean('status')->default(StatusDefault::ACTIVE);

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // One switch per user per type. The backfill relies on this: it uses
            // firstOrCreate, and two overlapping requests must not both win.
            $table->unique(['user_id', 'notification_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};

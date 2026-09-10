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
        // Notification types are rows rather than enum cases alone, so a new one
        // can be added — and an existing one silenced platform-wide — without a
        // deploy. NotificationTypeEnum stays as the seed source and the constant
        // the code refers to; the table is what the admin actually edits.
        Schema::create('notification_types', function (Blueprint $table) {
            $table->id();

            // The enum value. Unique because it is the natural key the seeder
            // matches on and the column every lookup goes through.
            $table->string('notification_type', 100)->unique();

            $table->string('title');
            $table->string('description', 1000)->nullable();

            $table->unsignedInteger('flow_order')->default(0);

            // Off here means nobody receives it, whatever their own preference
            // says. The per-user switch is in notification_preferences.
            $table->boolean('status')->default(StatusDefault::ACTIVE)->index();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_types');
    }
};

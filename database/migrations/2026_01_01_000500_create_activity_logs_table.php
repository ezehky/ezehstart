<?php

use App\Enums\ActivityPlatformEnum;
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
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('activity_log_action', 100)->index(); // ActivityActionEnum values
            $table->string('description', 1000);
            $table->json('original')->nullable();
            $table->json('changes')->nullable();
            $table->string('platform', 20)->default(ActivityPlatformEnum::WEB)->index();

            $table->string('device_type', 100);
            $table->string('browser', 100)->nullable();
            $table->string('os', 100)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->ipAddress();
            $table->nullableMorphs('loggable');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};

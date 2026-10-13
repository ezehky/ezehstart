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
        // A row is a feature the plan grants. No row, not granted.
        Schema::create('subscription_plan_features', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 100); // SubscriptionFeatureEnum

            // For a limit feature, how many. Null is unlimited; ignored for an
            // on/off feature.
            $table->unsignedInteger('feature_limit')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['subscription_plan_id', 'feature']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_features');
    }
};

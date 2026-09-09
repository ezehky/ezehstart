<?php

use App\Enums\StatusPolicy;
use App\Enums\StatusYes;
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
        Schema::create('policies', function (Blueprint $table) {
            $table->id();

            $table->string('policy_type', 30)->index(); // PolicyTypeEnum

            // Every version of a policy is its own row and none of them are ever
            // deleted: a consent record points at the exact text somebody accepted,
            // and evidence you can edit afterwards is not evidence.
            $table->string('version', 20);

            $table->string('title');
            $table->string('intro', 500)->nullable();
            $table->longText('content'); // Markdown, compiled by PolicyContentService

            // Consent is opt-in per policy — a cookie notice is informational and
            // making people tick a box for it only teaches them to tick boxes.
            $table->boolean('requires_consent')->default(StatusYes::NO);

            $table->tinyInteger('status')->default(StatusPolicy::DRAFT)->index();

            // Published now, in force later. A material change usually owes people
            // notice, and this is where that notice period lives.
            $table->timestamp('effective_at')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['policy_type', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};

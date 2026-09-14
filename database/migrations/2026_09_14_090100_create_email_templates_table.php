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
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('thumbnail_image_id')->nullable()->constrained('images')->nullOnDelete();
            $table->foreignId('footer_section_id')->nullable()->constrained('email_sections')->nullOnDelete();

            $table->string('name')->index();
            $table->string('description', 500)->nullable();

            // The block array. A campaign built from this template deep-copies it —
            // see EmailTemplateService::createCampaignFromTemplate() — so a later
            // change here never reaches a campaign that already exists.
            $table->json('content');

            // Global design settings: background, container width, font, etc.
            $table->json('design');

            $table->unsignedInteger('used_count')->default(0);

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};

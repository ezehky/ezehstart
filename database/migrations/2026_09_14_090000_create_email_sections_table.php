<?php

use App\Enums\EmailSectionTypeEnum;
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
        Schema::create('email_sections', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // One or more blocks, the same shape a template/campaign's own `content`
            // holds — a saved footer is not a special format, it is a block array an
            // admin chose to keep.
            $table->json('content');

            $table->string('email_section_type', 20)->default(EmailSectionTypeEnum::CUSTOM)->index();

            // One default per type — enforced in EmailSectionService::setDefault(),
            // not here: a unique index on (email_section_type, is_default) cannot
            // express "unique only when true" without a partial index, and this repo
            // targets more than one database driver.
            $table->boolean('is_default')->default(StatusYes::NO)->index();

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
        Schema::dropIfExists('email_sections');
    }
};

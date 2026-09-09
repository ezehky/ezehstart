<?php

use App\Enums\FaqTypeEnum;
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
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();

            $table->string('faq_type', 30)->default(FaqTypeEnum::GENERAL)->index();

            $table->string('question');

            // Text rather than a string: answers are markdown, and a paragraph with
            // a short list under it runs past 255 characters immediately.
            $table->text('answer');

            $table->unsignedInteger('flow_order')->default(0);
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
        Schema::dropIfExists('faqs');
    }
};

<?php

use App\Enums\StatusEmailCampaignRecipient;
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
        Schema::create('email_campaign_recipients', function (Blueprint $table) {
            $table->id();

            $table->foreignId('email_campaign_id')->constrained()->cascadeOnDelete();

            // Nullable: a "specific email addresses" recipient may not have an
            // account at all, and the row still has to exist for the delivery count.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('email')->index();

            $table->tinyInteger('status')->default(StatusEmailCampaignRecipient::PENDING)->index();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_campaign_recipients');
    }
};

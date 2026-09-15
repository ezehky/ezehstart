<?php

use App\Enums\EmailRecipientTypeEnum;
use App\Enums\EmailRecurrenceEnum;
use App\Enums\StatusEmailCampaign;
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
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // The template this was started from, kept only so the campaign list can
            // say "from Newsletter Template" — content/design were deep-copied at
            // creation and never read back from here again.
            $table->foreignId('email_template_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->foreignId('footer_section_id')->nullable()->constrained('email_sections')->nullOnDelete();

            // Internal only — never rendered into the email itself.
            $table->string('name');

            $table->string('subject');
            $table->string('preview_text', 500)->nullable();

            $table->string('from_name');
            $table->string('from_email');
            $table->string('reply_to')->nullable();

            $table->json('content');
            $table->json('design');

            $table->string('email_recipient_type', 20)->default(EmailRecipientTypeEnum::ALL_USERS);

            // Specific: {"emails": [...]}. Preferences: {"notification_types": [...]}.
            // All users needs no config at all.
            $table->json('recipient_config')->nullable();

            $table->unsignedInteger('estimated_recipients')->nullable();

            $table->tinyInteger('status')->default(StatusEmailCampaign::DRAFT)->index();

            $table->timestamp('scheduled_at')->nullable()->index();
            $table->string('timezone')->nullable();
            $table->timestamp('sent_at')->nullable();

            // A repeat never re-sends this row. Finishing a recurring campaign
            // copies it into the next occurrence — see
            // EmailCampaignService::spawnNextOccurrence() — which is what keeps the
            // Sent listing an honest record of what actually went out and when.
            $table->string('email_recurrence', 20)->default(EmailRecurrenceEnum::NONE);
            $table->timestamp('recurrence_ends_at')->nullable();

            // The occurrence this one was copied from, so a series can be followed
            // backwards. Nulls rather than cascades: deleting the first send of a
            // series must not take the rest of the archive with it.
            $table->foreignId('recurs_from_id')->nullable()->constrained('email_campaigns')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_campaigns');
    }
};

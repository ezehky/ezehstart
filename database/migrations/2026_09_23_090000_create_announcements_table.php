<?php

use App\Enums\AnnouncementLayoutEnum;
use App\Enums\StatusDefault;
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
        // The popup on the public pages: a promotion, a notice, or the newsletter
        // sign-up with a picture. Only the latest live row is ever shown — see
        // AnnouncementService::current() — so older ones are history, not a queue.
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();

            // Who wrote it. Kept when that account goes, because the announcement
            // still ran and the admin listing should still show it did.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Both optional: an image-only promo carries its message in the picture.
            $table->string('title')->nullable();
            $table->text('body')->nullable();

            // From the image library, like a post's cover — the usage row is what
            // stops the image being deleted while it is on the popup.
            $table->foreignId('image_id')->nullable()->constrained()->nullOnDelete();

            // Where clicking the picture goes, and the button that says so. The
            // label is optional: a linked picture is a link on its own.
            $table->string('link_url', 500)->nullable();
            $table->string('link_label', 60)->nullable();

            // Off leaves the picture on its own — the "promo only" shape.
            $table->boolean('show_newsletter')->default(StatusYes::YES);

            $table->string('layout', 20)->default(AnnouncementLayoutEnum::STACKED);

            // An open window at either end means "from now" and "until replaced".
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->boolean('status')->default(StatusDefault::ACTIVE)->index();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // The popup's own query: live, in its window, newest first.
            $table->index(['status', 'starts_at', 'ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};

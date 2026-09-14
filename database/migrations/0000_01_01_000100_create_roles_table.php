<?php

use App\Enums\StatusDefault;
use App\Enums\StatusYes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Runs before the users table, which carries the foreign key into this one.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            // Free text, written by an administrator: "Administrator", "Media",
            // "Support". There is no enum behind it — a role is a row, and the
            // starter ships no opinion about which ones a project needs.
            $table->string('name', 100);
            // The stable handle. The display name can be reworded without breaking a
            // seeder or a test that looks the role up.
            $table->string('slug', 120)->unique();
            $table->string('description', 255)->nullable();
            // The access map for every admin holding this role: navigation key =>
            // GateAccessEnum value, with the child keys dotted ("users.roles").
            // Null means the role has never been configured, which GateService
            // reads as "no gates" rather than "every gate" — a role nobody has
            // granted anything to must not open the whole workspace.
            $table->json('gates')->nullable();
            // An inactive role keeps its assignments but grants nothing, so access
            // can be suspended wholesale without unpicking who held what.
            $table->boolean('status')->default(StatusDefault::ACTIVE);
            // A protected role cannot be renamed away, deleted, or deactivated. The
            // install ships exactly one so there is always somewhere for the last
            // full-access administrator to stand.
            $table->boolean('is_protected')->default(StatusYes::NO);

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};

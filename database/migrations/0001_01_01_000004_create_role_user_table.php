<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Runs after both tables it joins. An admin carries any number of roles and
     * their maps are merged — see GateService::mapFor() — so the assignment is a
     * row here rather than a column on users.
     */
    public function up(): void
    {
        // A pivot row is never edited, only made and unmade, so created_at alone.
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();

            // Both cascade. Deleting a role takes its assignments with it, which is
            // safe because the delete guard already refuses a role anybody holds;
            // deleting an account takes nothing else with it.
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamp('created_at')->useCurrent();

            // Holding the same role twice is a double-submitted form, not more
            // access. The index is what makes it a no-op.
            $table->unique(['role_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};

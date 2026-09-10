<?php

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
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            // The per-administrator override, in the same shape as roles.gates.
            // Null is the normal state and means "inherit the role" — an empty
            // array does not, it means somebody deliberately took every gate away.
            // Only keys present here override; the rest still come from the role.
            $table->json('gates')->nullable();
            $table->boolean('status')->default(StatusDefault::ACTIVE);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};

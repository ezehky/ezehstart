<?php

use App\Enums\UserRoleEnum;
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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 20)->default(UserRoleEnum::USER)->index();
            // The access map for everybody holding this role: navigation key =>
            // GateAccessEnum value, with the child keys dotted ("users.roles").
            // Null means the role has never been configured, which GateService
            // reads as "no gates" rather than "every gate" — a role nobody has
            // granted anything to must not open the whole workspace.
            $table->json('gates')->nullable();

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

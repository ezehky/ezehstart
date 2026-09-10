<?php

use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;
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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email', 50)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('phone_number', 20)->nullable();

            $table->string('avatar')->nullable();

            // Which workspace this account signs in to. Fixed in code — see
            // UserTypeEnum, and bootstrap/app.php for what a new one costs.
            $table->string('type', 20)->default(UserTypeEnum::USER)->index();
            // The role, and only for an admin. A member has none: the member
            // workspace is not gated, so there is nothing for a role to say.
            $table->foreignId('role_id')->nullable()->constrained()->nullOnDelete();
            // This administrator's personal override, in the same shape as
            // roles.gates. Null is the normal state and means "inherit the role" —
            // an empty array does not, it means somebody deliberately took every
            // gate away. Only keys present here override; the rest come from the role.
            $table->json('gates')->nullable();

            $table->string('timezone')->default(config('app.timezone'));
            $table->ipAddress()->nullable();

            $table->tinyInteger('status')->default(StatusUser::ACTIVE);
            $table->timestamp('last_seen_at')->nullable();

            $table->rememberToken();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};

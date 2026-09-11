<?php

namespace App\Models;

use App\Enums\StatusDefault;
use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;
use App\Services\PolicyContentService;
use App\Traits\WithDynamicModelFormatting;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Unguarded]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, WithDynamicModelFormatting;

    /**
     * The type a brand-new account starts as, matching the column default.
     *
     * Declared here as well because a database default is only applied on insert and
     * never read back — an unsaved User would have a null type, and every branch that
     * asks which workspace it belongs to would fatal on it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'user_type' => UserTypeEnum::USER->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_seen_at' => 'datetime',
            'status' => StatusUser::class,
            'user_type' => UserTypeEnum::class,
            'gates' => AsArrayObject::class,
        ];
    }

    // Getters

    public function isAdmin(): bool
    {
        return $this->user_type->isAdmin();
    }

    public function isUser(): bool
    {
        return $this->user_type->isUser();
    }

    public function isType(UserTypeEnum $type): bool
    {
        return $this->user_type === $type;
    }

    /**
     * This administrator's own gate override as a plain array, for merging and
     * counting.
     *
     * Null still means "inherit the role" and empty still means "everything was
     * deliberately taken away" — this flattens both to `[]`, so only call it where
     * that difference has already been decided.
     */
    public function gatesArray(): array
    {
        return $this->gates?->toArray() ?? [];
    }

    /**
     * Does this account reach a gated workspace at all?
     *
     * An admin with no role, or with one that has been deactivated, signs in and
     * reaches nothing — which is a real state, not a broken one.
     */
    public function hasLiveRole(): bool
    {
        return $this->user_type->carriesRole() && (bool) $this->role?->grantsAccess();
    }

    public function firstName(): string
    {
        $first = (string) str($this->name)->before(' ');

        return $first !== '' ? $first : $this->name;
    }

    public function initials()
    {
        return str($this->name)
            ->explode(' ')
            ->map(fn ($part) => str($part)->substr(0, 1))
            ->take(2)
            ->join('');
    }

    public function profileCompletion(): int
    {
        $profile = $this->userProfile;
        $fields = [
            (bool) $this->avatar,
            (bool) $this->phone_number,
            (bool) $profile?->gender,
            (bool) $profile?->bio,
        ];

        return (int) round(collect($fields)->filter()->count() / \count($fields) * 100);
    }

    /**
     * The policies in force that this account has not accepted.
     *
     * Non-empty after a new version is published: consent is recorded against a
     * specific version, so superseding one puts everybody back in this list until
     * they accept the replacement.
     *
     * @return Collection<int, Policy>
     */
    public function outstandingConsents(): Collection
    {
        $accepted = $this->consents()->pluck('policy_id');

        return app(PolicyContentService::class)
            ->getCurrentRequiringConsent()
            ->reject(fn (Policy $policy) => $accepted->contains($policy->id))
            ->values();
    }

    // Relationships

    public function userProfile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /**
     * The admin role, or null. Members never have one.
     *
     * `gates` is in the select because GateService resolves this account's access
     * straight off the loaded role. Leave it out and every role map reads as null,
     * which looks exactly like "granted nothing" rather than like a bug.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)
            ->select('id', 'name', 'slug', 'gates', 'status', 'is_protected');
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(UserConsent::class);
    }

    public function connectedAccounts(): HasMany
    {
        return $this->hasMany(UserConnectedAccount::class);
    }

    /**
     * The second-factor enrolment, if there is one. A row exists from the moment
     * enrolment starts, so having one is not the same as having it switched on —
     * ask twoFactor?->status->isActive() for that.
     */
    public function twoFactor(): HasOne
    {
        return $this->hasOne(UserTwoFactor::class);
    }

    public function passwordHistories(): HasMany
    {
        return $this->hasMany(PasswordHistory::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    // Scopes

    #[Scope]
    protected function ofType(Builder $builder, UserTypeEnum $type): void
    {
        $builder->where('user_type', $type);
    }

    /**
     * Named for the two listings rather than for the enum cases: a scope called
     * isAdmin() would collide with the getter of the same name, and PHP would not
     * let the class load at all.
     */
    #[Scope]
    protected function admins(Builder $builder): void
    {
        $builder->where('user_type', UserTypeEnum::ADMIN);
    }

    #[Scope]
    protected function members(Builder $builder): void
    {
        $builder->where('user_type', UserTypeEnum::USER);
    }

    /**
     * Admins who cannot reach the workspace: no role, or one that is switched off.
     * A real state — an account promoted before a role was picked, or a whole role
     * suspended — so the admins listing can call it out rather than show a blank.
     */
    #[Scope]
    protected function withoutLiveRole(Builder $builder): void
    {
        $builder->where('user_type', UserTypeEnum::ADMIN)
            ->where(fn (Builder $query) => $query
                ->whereNull('role_id')
                ->orWhereHas('role', fn (Builder $role) => $role->where('status', StatusDefault::INACTIVE)));
    }
}

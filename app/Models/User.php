<?php

namespace App\Models;

use App\Enums\StatusDefault;
use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;
use App\Services\PolicyContentService;
use App\Services\RoleService;
use App\Traits\WithDynamicModelFormatting;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
            'deletion_requested_at' => 'datetime',
            'deletion_scheduled_at' => 'datetime',
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

    public function isAdministrator(): bool
    {
        return $this->user_type->carriesRole() &&
            $this->roles()->isProtected()->exists();
    }

    /**
     * This administrator's own gate override as a plain array, for merging and
     * counting.
     *
     * Null still means "inherit the roles" and empty still means "everything was
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
     * An admin with no roles, or with only deactivated ones, signs in and reaches
     * nothing — which is a real state, not a broken one.
     */
    public function hasLiveRole(): bool
    {
        return $this->user_type->carriesRole() && $this->liveRoles()->isNotEmpty();
    }

    /**
     * The roles that are actually granting something right now.
     *
     * An admin carries any number, and a switched-off one keeps its assignment
     * while granting nothing — so everything that reads access reads this rather
     * than the raw relation.
     *
     * @return Collection<int, Role>
     */
    public function liveRoles(): Collection
    {
        return $this->roles
            ->filter(fn (Role $role) => $role->grantsAccess())
            ->values();
    }

    /**
     * Does this account write for the blog?
     *
     * Asked by slug rather than by gate: plenty of roles reach the blog, but only the
     * author role says the person *is* an author — which is what decides whose byline
     * carries a bio and which posts they are held to. The role can be renamed freely;
     * the slug is what the blog is written against.
     */
    public function isAuthor(): bool
    {
        return $this->liveRoles()->contains('slug', RoleService::AUTHOR_SLUG);
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
     * The admin roles this account carries. users never have any.
     *
     * Many rather than one: an administrator can be Media *and* Support, and the
     * maps are merged — see GateService::mapFor(). Nothing here decides what that
     * merge means; ask GateService, never the relation.
     *
     * `gates` is in the select because GateService resolves this account's access
     * straight off the loaded roles. Leave it out and every role map reads as null,
     * which looks exactly like "granted nothing" rather than like a bug.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->select('roles.id', 'roles.name', 'roles.slug', 'roles.gates', 'roles.status', 'roles.is_protected')
            ->orderBy('roles.name');
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

    public function deletionReminders(): HasMany
    {
        return $this->hasMany(UserDeletionReminder::class);
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
    protected function users(Builder $builder): void
    {
        $builder->where('user_type', UserTypeEnum::USER)->registered();
    }

    /**
     * Rows that are accounts, as opposed to addresses the site is holding.
     *
     * A newsletter sign-up is a users row with no password and no verified
     * address — see StatusUser::NEWSLETTER_SUBSCRIBER for why it lives here. It
     * carries the member type like any other member, so every listing, metric and
     * trend that means "our members" has to say so, or the numbers become a count
     * of the mailing list instead.
     */
    #[Scope]
    protected function registered(Builder $builder): void
    {
        $builder->where('status', '!=', StatusUser::NEWSLETTER_SUBSCRIBER);
    }

    /**
     * The mailing list, for the admin screens that report on it.
     */
    #[Scope]
    protected function newsletterSubscribers(Builder $builder): void
    {
        $builder->where('status', StatusUser::NEWSLETTER_SUBSCRIBER);
    }

    /**
     * Admins who cannot reach the workspace: no roles at all, or only switched-off
     * ones. A real state — an account promoted before a role was picked, or a whole
     * role suspended — so the admins listing can call it out rather than show a blank.
     *
     * Asked as "has no live role" rather than "has an inactive role": one live role
     * is enough to reach the workspace, however many dead ones sit beside it.
     */
    #[Scope]
    protected function withoutLiveRole(Builder $builder): void
    {
        $builder->where('user_type', UserTypeEnum::ADMIN)
            ->whereDoesntHave('roles', fn (Builder $role) => $role->where('status', StatusDefault::ACTIVE));
    }

    /**
     * Admins holding one specific role. The admins listing filters on it.
     */
    #[Scope]
    protected function holdingRole(Builder $builder, Role|int $role): void
    {
        $builder->whereHas('roles', fn (Builder $query) => $query
            ->whereKey($role instanceof Role ? $role->id : $role));
    }

    /**
     * Accounts inside the deletion grace period. The status is asked for as well
     * as the date because an administrator can put an account back to active,
     * and that has to count as a cancellation rather than leave a stale date the
     * sweep would still act on.
     */
    #[Scope]
    protected function pendingDeletion(Builder $builder): void
    {
        $builder->where('status', StatusUser::PENDING_DELETION)
            ->whereNotNull('deletion_scheduled_at');
    }

    /**
     * Pending accounts whose date has passed. Deliberately unbounded at the far
     * end, unlike the reminders: a grace period that expired during an outage
     * has still expired, and the account holder asked for this. Catching up is
     * the right behaviour here even though it is the wrong behaviour for a
     * warning about a date that is already behind us.
     */
    #[Scope]
    protected function dueForDeletion(Builder $builder): void
    {
        $builder->pendingDeletion()->where('deletion_scheduled_at', '<=', now());
    }
}

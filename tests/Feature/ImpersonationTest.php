<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;
use App\Models\ActivityLog;
use App\Services\ImpersonationService;
use App\Services\SiteConfigurationService;
use Livewire\Livewire;

beforeEach(function () {
    app(SiteConfigurationService::class)->update(initials: true);

    $this->admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);
    $this->member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// STARTING

test('an admin can view the site as a member', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.users')
        ->call('impersonate', $this->member->id)
        ->assertRedirect(UserTypeEnum::USER->dashboardRoute());

    expect(auth()->id())->toBe($this->member->id)
        ->and(app(ImpersonationService::class)->isImpersonating())->toBeTrue()
        ->and(app(ImpersonationService::class)->impersonator()->id)->toBe($this->admin->id);
});

test('the real identity is kept in the session, not handed out in the url', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.users')
        ->call('impersonate', $this->member->id);

    // A token in a link is a token that gets copied, logged and replayed.
    expect(session(ImpersonationService::SESSION_KEY))->toBe($this->admin->id);
});

test('starting is written to the log against the admin, not the member', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.users')
        ->call('impersonate', $this->member->id);

    $entry = ActivityLog::query()
        ->where('activity_log_action', ActivityActionEnum::IMPERSONATION_START)
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->user_id)->toBe($this->admin->id)
        ->and($entry->description)->toContain($this->member->email);
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// WHAT IS REFUSED

test('an administrator can never be impersonated', function () {
    $other = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);

    $this->actingAs($this->admin);

    // Becoming a peer is privilege escalation with a support story attached.
    expect(app(ImpersonationService::class)->blockedReason($other))
        ->toBe('An administrator cannot be impersonated.');

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.users')
        ->call('impersonate', $other->id)
        ->assertHasErrors();

    expect(auth()->id())->toBe($this->admin->id);
});

test('an account that cannot sign in is not worth becoming', function () {
    $suspended = userOfType(UserTypeEnum::USER, [
        'email_verified_at' => now(),
        'status' => StatusUser::SUSPENDED,
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.users')
        ->call('impersonate', $suspended->id)
        ->assertHasErrors();
});

test('an admin without full access over members cannot impersonate', function () {
    $narrow = adminWithRoles(roleWithGates('Support', ['users.users-list' => GateAccessEnum::MODIFY->value]));

    Livewire::actingAs($narrow)
        ->test('pages::admin.users.users')
        ->call('impersonate', $this->member->id)
        ->assertHasErrors();

    expect(auth()->id())->toBe($narrow->id);
});

test('impersonation cannot be nested', function () {
    $second = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.users')
        ->call('impersonate', $this->member->id);

    expect(app(ImpersonationService::class)->blockedReason($second))
        ->toContain('already viewing the site as somebody else');
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// ACCOUNT-ALTERING SCREENS ARE CLOSED

test('the security screen is closed while impersonating', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.users')
        ->call('impersonate', $this->member->id);

    // Two-factor, the email change, the session list and connected accounts all live
    // here. Impersonation is for looking, not for taking an account over.
    $this->get(route('user.security-settings'))->assertNotFound();
});

test('the delete-account screen is closed while impersonating', function () {
    $this->member->userProfile()->update(['settings' => ['can-delete-account' => true]]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.users')
        ->call('impersonate', $this->member->id);

    $this->get(route('user.delete-account'))->assertNotFound();
});

test('the data download is closed while impersonating', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.users')
        ->call('impersonate', $this->member->id);

    // Somebody else's personal data is not a support tool.
    $this->get(route('user.download-data'))->assertNotFound();
});

test('a password cannot be changed while impersonating', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.users')
        ->call('impersonate', $this->member->id);

    // The profile screen stays open — looking at it is the point — so the one write
    // on it guards itself.
    Livewire::test('pages::shared.profile')
        ->call('passwordStep1')
        ->assertHasErrors();
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// COMING BACK

test('the admin can return to their own account', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.users')
        ->call('impersonate', $this->member->id);

    $this->get(route('impersonation.stop'))->assertRedirect(UserTypeEnum::ADMIN->dashboardRoute());

    expect(auth()->id())->toBe($this->admin->id)
        ->and(app(ImpersonationService::class)->isImpersonating())->toBeFalse();
});

test('returning is logged against the admin too', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.users')
        ->call('impersonate', $this->member->id);

    $this->get(route('impersonation.stop'));

    $entry = ActivityLog::query()
        ->where('activity_log_action', ActivityActionEnum::IMPERSONATION_STOP)
        ->first();

    // Both ends, so the trail says who was acting as whom and until when rather than
    // going quiet halfway through.
    expect($entry)->not->toBeNull()
        ->and($entry->user_id)->toBe($this->admin->id)
        ->and($entry->description)->toContain($this->member->email);
});

test('the stop route is a 404 when nothing is being impersonated', function () {
    $this->actingAs($this->admin)->get(route('impersonation.stop'))->assertNotFound();
});

test('a sitting that has run out ends itself on the next request', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.users')
        ->call('impersonate', $this->member->id);

    // An administrator who wanders off is otherwise a live session with somebody
    // else's identity attached to it.
    $this->travel(ImpersonationService::MAX_MINUTES + 1)->minutes();

    expect(app(ImpersonationService::class)->hasExpired())->toBeTrue();

    $this->get(route('user.dashboard'))->assertRedirect(route('admin.dashboard'));

    expect(auth()->id())->toBe($this->admin->id)
        ->and(app(ImpersonationService::class)->isImpersonating())->toBeFalse();
});

test('the banner is on every member screen while it runs', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.users')
        ->call('impersonate', $this->member->id);

    // Not a toast and not scrollable away: the failure mode is an administrator
    // forgetting which account they are in.
    $this->get(route('user.dashboard'))
        ->assertOk()
        ->assertSee('Return to my account')
        ->assertSee($this->member->name);
});

<?php

use App\Enums\UserRoleEnum;
use App\Models\User;
use App\Services\PasswordSecurityService;
use App\Services\SiteConfigurationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    $this->member = userWithRole(UserRoleEnum::USER, [
        'email_verified_at' => now(),
        'password' => 'Correct-horse-1!',
    ]);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// LOGIN THROTTLE

test('a wrong password is rejected without throttling on the first attempts', function () {
    Livewire::test('pages::auth.login')
        ->set('email', $this->member->email)
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('email');
});

test('too many failed sign-ins lock the account out', function () {
    app(SiteConfigurationService::class)->update(initials: true);

    // The default is five attempts, and the sixth is the one that must be refused
    // outright rather than merely failing on credentials again.
    foreach (range(1, 5) as $attempt) {
        Livewire::test('pages::auth.login')
            ->set('email', $this->member->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email');
    }

    Livewire::test('pages::auth.login')
        ->set('email', $this->member->email)
        ->set('password', 'Correct-horse-1!')
        ->call('login')
        ->assertHasErrors('email');

    // Even the right password does not get through while the lockout stands.
    $this->assertGuest();
});

test('the throttle is keyed on the email and ip together, so one account cannot lock out another', function () {
    $other = userWithRole(UserRoleEnum::USER, [
        'email_verified_at' => now(),
        'password' => 'Correct-horse-1!',
    ]);

    foreach (range(1, 6) as $attempt) {
        Livewire::test('pages::auth.login')
            ->set('email', $this->member->email)
            ->set('password', 'wrong-password')
            ->call('login');
    }

    // A different account from the same address is untouched by the first one's
    // lockout — otherwise anybody could lock out an email they know.
    Livewire::test('pages::auth.login')
        ->set('email', $other->email)
        ->set('password', 'Correct-horse-1!')
        ->call('login')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($other);
});

test('a successful sign-in clears the failure count', function () {
    foreach (range(1, 3) as $attempt) {
        Livewire::test('pages::auth.login')
            ->set('email', $this->member->email)
            ->set('password', 'wrong-password')
            ->call('login');
    }

    Livewire::test('pages::auth.login')
        ->set('email', $this->member->email)
        ->set('password', 'Correct-horse-1!')
        ->call('login')
        ->assertHasNoErrors();

    expect(RateLimiter::attempts('login|'.mb_strtolower($this->member->email).'|127.0.0.1'))->toBe(0);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// PASSWORD STRENGTH

test('the strength rule tightens and relaxes with the site configuration', function () {
    $service = app(PasswordSecurityService::class);

    app(SiteConfigurationService::class)->update(initials: true);
    expect($service->strongPasswordEnabled())->toBeTrue()
        ->and($service->note())->toContain('symbol');

    $configs = app(SiteConfigurationService::class)->getConfigs(raw: true);
    data_set($configs, 'security.strong-password', false);
    app(SiteConfigurationService::class)->update($configs);

    expect($service->strongPasswordEnabled())->toBeFalse()
        ->and($service->note())->not->toContain('symbol');
});

test('an unconfigured install still enforces strong passwords', function () {
    // Nothing saved at all. The strict setting has to be the fallback, or a fresh
    // install quietly ships with the weaker rule.
    expect(app(SiteConfigurationService::class)->getConfigs(raw: true))->toBeEmpty()
        ->and(app(PasswordSecurityService::class)->strongPasswordEnabled())->toBeTrue();
});

test('a switch turned off is honoured rather than replaced by its default', function () {
    // kSiteConfig() would hand back the default here, because false is falsy.
    // kSiteFlag() is the reason this passes.
    app(SiteConfigurationService::class)->update(['security' => ['two-factor' => false]]);

    expect(kSiteFlag('security', 'two-factor', true))->toBeFalse();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// PASSWORD HISTORY

test('a password the account has used before is refused', function () {
    app(SiteConfigurationService::class)->update(initials: true);

    $service = app(PasswordSecurityService::class);

    // The password in force counts as used, even with no history rows yet.
    expect($service->hasBeenUsed($this->member, 'Correct-horse-1!'))->toBeTrue()
        ->and($service->hasBeenUsed($this->member, 'Totally-new-1!'))->toBeFalse();

    $service->updatePassword($this->member, 'Totally-new-1!');

    // Both the new one and the one it replaced are now unavailable.
    expect($service->hasBeenUsed($this->member->fresh(), 'Totally-new-1!'))->toBeTrue()
        ->and($service->hasBeenUsed($this->member->fresh(), 'Correct-horse-1!'))->toBeTrue();
});

test('history keeps only as many hashes as the configured depth', function () {
    app(SiteConfigurationService::class)->update([
        'security' => ['password-history' => true, 'password-history-depth' => 2],
    ]);

    $service = app(PasswordSecurityService::class);

    foreach (['One-pass-1!', 'Two-pass-2!', 'Three-pass-3!', 'Four-pass-4!'] as $password) {
        $service->updatePassword($this->member->fresh(), $password);
    }

    expect($this->member->passwordHistories()->count())->toBe(2);
});

test('history stores the previous hash, never the plain password', function () {
    app(SiteConfigurationService::class)->update(initials: true);

    $previous = $this->member->password;

    app(PasswordSecurityService::class)->updatePassword($this->member, 'Totally-new-1!');

    $stored = $this->member->passwordHistories()->first();

    expect($stored->password)->toBe($previous)
        ->and($stored->password)->not->toBe('Correct-horse-1!')
        ->and(Hash::check('Correct-horse-1!', $stored->password))->toBeTrue();
});

test('history is skipped entirely when the feature is switched off', function () {
    app(SiteConfigurationService::class)->update(['security' => ['password-history' => false]]);

    $service = app(PasswordSecurityService::class);

    $service->updatePassword($this->member, 'Totally-new-1!');

    expect($this->member->passwordHistories()->count())->toBe(0)
        ->and($service->hasBeenUsed($this->member->fresh(), 'Correct-horse-1!'))->toBeFalse();
});

test('the security page refuses a reused password', function () {
    app(SiteConfigurationService::class)->update(initials: true);

    app(PasswordSecurityService::class)->updatePassword($this->member, 'Totally-new-1!');

    Livewire::actingAs($this->member->fresh())
        ->test('pages::user.account.security-settings')
        ->set('passwordStep', 3)
        ->set('new_password', 'Correct-horse-1!')
        ->set('new_password_confirmation', 'Correct-horse-1!')
        ->call('passwordStep3')
        ->assertHasErrors('new_password');
});

test('the depth is clamped so a zero cannot silently disable the feature', function () {
    app(SiteConfigurationService::class)->update([
        'security' => ['password-history' => true, 'password-history-depth' => 0],
    ]);

    expect(app(PasswordSecurityService::class)->historyDepth())->toBeGreaterThanOrEqual(1);
});

test('a user with no password at all is not treated as having reused one', function () {
    $social = User::factory()->create(['password' => null]);

    app(SiteConfigurationService::class)->update(initials: true);

    expect(app(PasswordSecurityService::class)->hasBeenUsed($social, 'Anything-1!'))->toBeFalse();
});

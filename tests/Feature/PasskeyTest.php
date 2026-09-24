<?php

use App\Enums\ActivityActionEnum;
use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ImpersonationService;
use App\Services\PasskeyService;
use App\Services\SiteConfigurationService;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\LaravelPasskeys\Models\Passkey;

beforeEach(function () {
    $this->member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);
});

/**
 * A passkey row for an account, written straight to the table — the WebAuthn
 * ceremony needs a real authenticator, so these tests stub the one service call
 * that checks the signature and cover everything around it.
 */
function passkeyFor($user, string $name = 'Work laptop'): int
{
    return DB::table('passkeys')->insertGetId([
        'authenticatable_id' => $user->id,
        'name' => $name,
        'credential_id' => 'cred-'.Str::random(8),
        'data' => '{}',
    ]);
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// AVAILABILITY

test('passkeys are on unless the site switch turns them off', function () {
    expect(app(PasskeyService::class)->isAvailable())->toBeTrue();

    app(SiteConfigurationService::class)->update(['security' => ['passkeys' => false]]);

    expect(app(PasskeyService::class)->isAvailable())->toBeFalse();
});

test('the switch closes passkey sign-in as well as hiding the button', function () {
    app(SiteConfigurationService::class)->update(['security' => ['passkeys' => false]]);

    Livewire::test('pages::auth.login')
        ->assertDontSee('Sign in with a passkey')
        ->call('passkeyOptions')
        ->assertNotFound();
});

test('the login page offers a passkey while the switch is on', function () {
    $this->get(route('login'))->assertOk()->assertSee('Sign in with a passkey');
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// SIGN IN

test('the challenge demands user verification', function () {
    $options = json_decode(app(PasskeyService::class)->authenticationOptions(), true);

    expect($options['userVerification'])->toBe('required')
        ->and(session('passkeys.authentication-options'))->not->toBeNull();
});

test('a recognised passkey signs the account in and is logged', function () {
    $this->partialMock(PasskeyService::class, fn ($mock) => $mock->shouldReceive('authenticate')->andReturn($this->member));

    Livewire::test('pages::auth.login')
        ->call('loginWithPasskey', '{}')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(auth()->id())->toBe($this->member->id)
        ->and(ActivityLog::query()->where('activity_log_action', ActivityActionEnum::LOGIN)->latest('id')->value('description'))
        ->toContain('passkey');
});

test('an unrecognised passkey is refused and counted against the throttle', function () {
    Livewire::test('pages::auth.login')
        ->call('loginWithPasskey', '{"not":"a credential"}')
        ->assertHasErrors('passkey');

    expect(auth()->check())->toBeFalse()
        ->and(RateLimiter::attempts('passkey|passkey|127.0.0.1'))->toBe(1);
});

test('a suspended account cannot sign in with its passkey', function () {
    $this->member->update(['status' => StatusUser::SUSPENDED]);

    $this->partialMock(PasskeyService::class, fn ($mock) => $mock->shouldReceive('authenticate')->andReturn($this->member->fresh()));

    Livewire::test('pages::auth.login')
        ->call('loginWithPasskey', '{}')
        ->assertHasErrors('passkey');

    expect(auth()->check())->toBeFalse();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// SIGN UP

/**
 * The sign-up form, filled in as far as a passkey sign-up needs — no password.
 */
function passkeySignup(): Testable
{
    return Livewire::test('pages::auth.register')
        ->set('name', 'Ada Lovelace')
        ->set('email', 'ada@example.test')
        ->set('agreed_to_terms', true);
}

test('the sign-up page offers a passkey while the switch is on', function () {
    $this->get(route('register'))->assertOk()->assertSee('Create account with a passkey');

    app(SiteConfigurationService::class)->update(['security' => ['passkeys' => false]]);

    $this->get(route('register'))->assertOk()->assertDontSee('Create account with a passkey');
});

test('a passkey sign-up asks for the details, but not a password', function () {
    Livewire::test('pages::auth.register')
        ->call('passkeyOptions')
        ->assertHasErrors(['name', 'email', 'agreed_to_terms'])
        ->assertHasNoErrors('password');
});

test('the sign-up challenge is for the address typed and demands user verification', function () {
    $options = json_decode(passkeySignup()->call('passkeyOptions')->assertHasNoErrors()->effects['returns'][0], true);

    expect($options['user']['name'])->toBe('ada@example.test')
        ->and($options['user']['displayName'])->toBe('Ada Lovelace')
        ->and($options['authenticatorSelection']['userVerification'])->toBe('required');
});

test('a key that verifies opens a signed-in account with no password', function () {
    $this->partialMock(PasskeyService::class, fn ($mock) => $mock->shouldReceive('completeSignup')
        ->andReturnUsing(fn (User $user) => Passkey::query()->find(passkeyFor($user, 'Added at sign-up'))));

    passkeySignup()
        ->call('passkeyOptions')
        ->call('registerWithPasskey', '{"id":"signed"}')
        ->assertHasNoErrors()
        ->assertRedirect();

    $user = User::query()->firstWhere('email', 'ada@example.test');

    expect($user)->not->toBeNull()
        ->and($user->password)->toBeNull()
        ->and($user->passkeys()->count())->toBe(1)
        ->and(auth()->id())->toBe($user->id);
});

test('a key that does not verify leaves no account behind', function () {
    passkeySignup()
        ->call('passkeyOptions')
        ->call('registerWithPasskey', '{"id":"forged"}')
        ->assertHasErrors('passkey');

    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeFalse()
        ->and(auth()->check())->toBeFalse();
});

test('a key answer with no challenge issued for that address creates nothing', function () {
    passkeySignup()
        ->call('registerWithPasskey', '{"id":"signed"}')
        ->assertHasErrors('passkey');

    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeFalse();
});

test('the switch closes passkey sign-up as well as hiding the button', function () {
    app(SiteConfigurationService::class)->update(['security' => ['passkeys' => false]]);

    passkeySignup()->call('passkeyOptions')->assertNotFound();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// MANAGING KEYS

test('a member manages passkeys on the security tab', function () {
    passkeyFor($this->member);

    $this->actingAs($this->member)
        ->get(route('user.security-settings'))
        ->assertOk()
        ->assertSee('Passkeys')
        ->assertSee('Work laptop');
});

test('an administrator manages passkeys and two factor on the profile', function () {
    app(SiteConfigurationService::class)->update(['security' => ['two-factor' => true]]);

    $this->actingAs(userOfType(UserTypeEnum::ADMIN))
        ->get(route('admin.profile'))
        ->assertOk()
        ->assertSee('Passkeys')
        ->assertSee('Two-factor authentication');
});

test('registration options need a name for the device first', function () {
    Livewire::actingAs($this->member)
        ->test('livewire.account.passkeys')
        ->call('passkeyOptions')
        ->assertHasErrors('name');
});

test('a credential the browser did not really sign is not saved', function () {
    Livewire::actingAs($this->member)
        ->test('livewire.account.passkeys')
        ->set('name', 'Phone')
        ->call('passkeyOptions')
        ->call('storePasskey', '{"id":"forged"}')
        ->assertHasErrors('name');

    expect($this->member->passkeys()->count())->toBe(0);
});

test('removing a passkey is scoped to the account and logged', function () {
    $mine = passkeyFor($this->member);
    $theirs = passkeyFor(userOfType(UserTypeEnum::USER));

    Livewire::actingAs($this->member)
        ->test('livewire.account.passkeys')
        ->call('confirmRemove', $theirs)
        ->call('removePasskey')
        ->assertHasErrors();

    Livewire::actingAs($this->member)
        ->test('livewire.account.passkeys')
        ->call('confirmRemove', $mine)
        ->call('removePasskey');

    expect(DB::table('passkeys')->where('id', $mine)->exists())->toBeFalse()
        ->and(DB::table('passkeys')->where('id', $theirs)->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('activity_log_action', ActivityActionEnum::PASSKEY_DELETE)->exists())->toBeTrue();
});

test('the passkey panel is closed while impersonating', function () {
    $this->actingAs($this->member);
    session()->put('impersonation.admin-id', 1);

    $this->partialMock(ImpersonationService::class, fn ($mock) => $mock->shouldReceive('isImpersonating')->andReturn(true));

    Livewire::test('livewire.account.passkeys')->assertNotFound();
});

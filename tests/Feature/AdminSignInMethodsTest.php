<?php

use App\Enums\SocialProviderEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;
use App\Services\PasswordlessOtpService;
use App\Services\SiteConfigurationService;
use App\Services\SocialAccountService;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    Mail::fake();

    $this->admin = userOfType(UserTypeEnum::ADMIN, [
        'email' => 'admin@example.test',
        'email_verified_at' => now(),
    ]);
});

/**
 * Put a code in the cache the way the service would, and hand the plain code back.
 */
function issuePasswordlessCode(string $email): string
{
    $code = '123456';
    Cache::put('passwordless-otp:'.sha1(mb_strtolower($email)), Hash::make($code), now()->addMinutes(10));

    return $code;
}

test('an administrator can sign in with an email code', function () {
    Livewire::test('pages::auth.passwordless')
        ->set('email', $this->admin->email)
        ->call('submitEmail')
        ->assertHasNoErrors()
        ->assertSet('step', 'code');

    Livewire::test('pages::auth.passwordless')
        ->set('email', $this->admin->email)
        ->set('step', 'code')
        ->set('otp', issuePasswordlessCode($this->admin->email))
        ->call('verify')
        ->assertRedirect();

    expect(auth()->id())->toBe($this->admin->id);
});

test('an email code still owes the second factor', function () {
    app(SiteConfigurationService::class)->update(['security' => ['two-factor' => true]]);

    $service = app(TwoFactorService::class);
    $twoFactor = $service->beginEnrolment($this->admin);
    $service->confirm($this->admin->fresh(), (new Google2FA)->getCurrentOtp($twoFactor->secret));

    Livewire::test('pages::auth.passwordless')
        ->set('email', $this->admin->email)
        ->set('step', 'code')
        ->set('otp', issuePasswordlessCode($this->admin->email))
        ->call('verify')
        ->assertRedirect(route('two-factor.challenge'));

    // Stood back down until the code from the app is in.
    expect(auth()->check())->toBeFalse();
});

test('a provider identity matching an administrator is refused', function () {
    $socialite = new SocialiteUser;
    $socialite->map(['id' => 'g-1', 'email' => $this->admin->email, 'name' => 'Admin']);

    $result = app(SocialAccountService::class)->resolve(SocialProviderEnum::cases()[0], $socialite);

    expect($result['user'])->toBeNull()
        ->and($result['error'])->toContain('Administrator accounts cannot use social sign-in')
        ->and($this->admin->connectedAccounts()->count())->toBe(0);
});

test('a link made before promotion stops working once the account is an administrator', function () {
    $provider = SocialProviderEnum::cases()[0];

    $this->admin->connectedAccounts()->create([
        'provider' => $provider,
        'provider_id' => 'g-2',
        'status' => StatusDefault::ACTIVE,
    ]);

    $socialite = new SocialiteUser;
    $socialite->map(['id' => 'g-2', 'email' => 'other@example.test', 'name' => 'Admin']);

    expect(app(SocialAccountService::class)->resolve($provider, $socialite)['error'])->not->toBeNull();
});

test('members may still use social sign-in', function () {
    $member = userOfType(UserTypeEnum::USER);

    expect(app(SocialAccountService::class)->acceptsAccount($member))->toBeTrue()
        ->and(app(SocialAccountService::class)->acceptsAccount($this->admin))->toBeFalse();
});

test('passwordless still refuses a suspended administrator', function () {
    $this->admin->update(['status' => StatusUser::SUSPENDED]);

    Livewire::test('pages::auth.passwordless')
        ->set('email', $this->admin->email)
        ->call('submitEmail')
        ->assertHasErrors('email');

    expect(app(PasswordlessOtpService::class)->secondsUntilResendFor($this->admin->email))->toBe(0);
});

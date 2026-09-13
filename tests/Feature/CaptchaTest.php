<?php

use App\Enums\UserTypeEnum;
use App\Services\CaptchaService;
use App\Services\SiteConfigurationService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Put the Turnstile keys in the environment. Availability is the switch and the
 * keys together, so every test that wants the captcha on has to do both.
 */
function turnstileKeys(): void
{
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);
}

function captchaSwitch(bool $on): void
{
    app(SiteConfigurationService::class)->update(['security' => ['captcha' => $on]]);
}

/**
 * What Cloudflare answers. The rule never trusts the token on its own, so this is
 * the only thing that decides whether a submit gets through.
 */
function turnstileAnswers(bool $success): void
{
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => $success]),
    ]);
}

/**
 * The registration form, filled in and ready to submit.
 */
function registrationForm(array $extra = []): Testable
{
    $form = Livewire::test('pages::auth.register')
        ->set('name', 'Ada Lovelace')
        ->set('email', 'ada@example.test')
        ->set('password', 'Password123!')
        ->set('password_confirmation', 'Password123!')
        ->set('agreed_to_terms', true);

    foreach ($extra as $property => $value) {
        $form->set($property, $value);
    }

    return $form;
}

/**
 * Fail two sign-ins from this address, which is what puts the login form over the
 * line into asking for a captcha.
 */
function failSignInsTwice(string $email): void
{
    foreach (range(1, 2) as $attempt) {
        Livewire::test('pages::auth.login')
            ->set('email', $email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email');
    }
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// AVAILABILITY

test('the captcha is off until an administrator turns it on', function () {
    turnstileKeys();

    expect(app(CaptchaService::class)->isAvailable())->toBeFalse();
});

test('the switch cannot turn the captcha on without the keys', function () {
    captchaSwitch(true);

    config(['services.turnstile.key' => null, 'services.turnstile.secret' => null]);

    expect(app(CaptchaService::class)->isConfigured())->toBeFalse()
        ->and(app(CaptchaService::class)->isAvailable())->toBeFalse();
});

test('the switch and the keys together turn it on', function () {
    turnstileKeys();
    captchaSwitch(true);

    expect(app(CaptchaService::class)->isAvailable())->toBeTrue();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE FORMS THAT ALWAYS ASK

test('registration is unchanged while the captcha is off', function () {
    Mail::fake();
    Http::preventStrayRequests();

    registrationForm()->call('register');

    // Nothing was asked of Cloudflare, because nothing was switched on.
    $this->assertAuthenticated();
});

test('registration refuses a submit with no token once the captcha is on', function () {
    Mail::fake();
    turnstileKeys();
    captchaSwitch(true);

    registrationForm()
        ->call('register')
        ->assertHasErrors('captcha');

    $this->assertGuest();
});

test('registration goes through on a token cloudflare confirms', function () {
    Mail::fake();
    turnstileKeys();
    captchaSwitch(true);
    turnstileAnswers(true);

    registrationForm(['captcha' => 'a-token'])->call('register');

    $this->assertAuthenticated();
});

test('a token cloudflare refuses is not a pass', function () {
    Mail::fake();
    turnstileKeys();
    captchaSwitch(true);
    turnstileAnswers(false);

    registrationForm(['captcha' => 'a-forged-token'])
        ->call('register')
        ->assertHasErrors('captcha');

    $this->assertGuest();
});

test('a spent token is thrown away so the widget issues another', function () {
    Mail::fake();
    turnstileKeys();
    captchaSwitch(true);
    turnstileAnswers(true);

    // A token Cloudflare accepts, on a form that fails for another reason. The
    // token has been spent proving it, so holding on to it would have the visitor
    // resubmitting something Cloudflare will refuse the second time.
    registrationForm(['captcha' => 'a-token', 'agreed_to_terms' => false])
        ->call('register')
        ->assertHasErrors('agreed_to_terms')
        ->assertSet('captcha', null);
});

test('a captcha that cannot be reached fails closed', function () {
    turnstileKeys();
    captchaSwitch(true);

    Http::fake(fn () => throw new ConnectionException('unreachable'));

    expect(app(CaptchaService::class)->verify('a-token'))->toBeFalse();
});

test('the password reset form asks before it sends anybody an email', function () {
    Mail::fake();
    turnstileKeys();
    captchaSwitch(true);

    $user = userOfType(UserTypeEnum::USER);

    Livewire::test('pages::auth.forgot-password')
        ->set('email', $user->email)
        ->call('step1')
        ->assertHasErrors('captcha');

    Mail::assertNothingSent();
});

test('the passwordless form asks before it sends anybody an email', function () {
    Mail::fake();
    turnstileKeys();
    captchaSwitch(true);

    Livewire::test('pages::auth.passwordless')
        ->set('email', 'stranger@example.test')
        ->call('submitEmail')
        ->assertHasErrors('captcha');

    Mail::assertNothingSent();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE SIGN-IN CHALLENGE

test('signing in the first time is not asked for a captcha', function () {
    Mail::fake();
    turnstileKeys();
    captchaSwitch(true);
    Http::preventStrayRequests();

    $user = userOfType(UserTypeEnum::USER, ['password' => Hash::make('Password123!')]);

    Livewire::test('pages::auth.login')
        ->set('email', $user->email)
        ->set('password', 'Password123!')
        ->call('login');

    $this->assertAuthenticatedAs($user);
});

test('an address that keeps failing is asked for one', function () {
    Mail::fake();
    turnstileKeys();
    captchaSwitch(true);

    $user = userOfType(UserTypeEnum::USER, ['password' => Hash::make('Password123!')]);

    failSignInsTwice($user->email);

    // The next attempt is refused on the missing token, and the right password does
    // not get past it — hiding the widget is the courtesy, rules() is the boundary,
    // and a client that never rendered it still has to produce one.
    Livewire::test('pages::auth.login')
        ->set('email', $user->email)
        ->set('password', 'Password123!')
        ->call('login')
        ->assertHasErrors('captcha');

    $this->assertGuest();
});

test('a challenged address gets in with a token cloudflare confirms', function () {
    Mail::fake();
    turnstileKeys();
    captchaSwitch(true);

    $user = userOfType(UserTypeEnum::USER, ['password' => Hash::make('Password123!')]);

    failSignInsTwice($user->email);

    turnstileAnswers(true);

    Livewire::test('pages::auth.login')
        ->set('email', $user->email)
        ->set('password', 'Password123!')
        ->set('captcha', 'a-token')
        ->call('login');

    $this->assertAuthenticatedAs($user);
});

test('the challenge counts by address, so it survives a page refresh', function () {
    turnstileKeys();
    captchaSwitch(true);

    $user = userOfType(UserTypeEnum::USER, ['password' => Hash::make('Password123!')]);

    failSignInsTwice($user->email);

    // A fresh component with nothing typed into it — which is what a refresh is —
    // still renders the widget.
    Livewire::test('pages::auth.login')
        ->assertSee('turnstileWidget', escape: false);
});

<?php

use App\Enums\PolicyTypeEnum;
use App\Enums\StatusPolicy;
use App\Enums\StatusYes;
use App\Models\Policy;
use App\Models\User;
use App\Models\UserConsent;
use App\Services\UserService;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

// ==================== Registering

test('registering records consent against every policy that requires it', function () {
    $terms = publishedPolicy(PolicyTypeEnum::TERMS);
    $privacy = publishedPolicy(PolicyTypeEnum::PRIVACY);

    // Informational, so nobody is asked to accept it.
    publishedPolicy(PolicyTypeEnum::COOKIES, ['requires_consent' => StatusYes::NO]);

    Livewire::test('pages::auth.register')
        ->set('name', 'Ada Lovelace')
        ->set('email', 'ada@example.test')
        ->set('password', 'Password123!')
        ->set('password_confirmation', 'Password123!')
        ->set('agreed_to_terms', true)
        ->call('register');

    $user = User::query()->where('email', 'ada@example.test')->first();

    expect($user)->not->toBeNull()
        ->and($user->consents()->count())->toBe(2)
        ->and($user->consents()->pluck('policy_id')->all())
        ->toEqualCanonicalizing([$terms->id, $privacy->id]);
});

/**
 * The record is only worth having if it says who accepted what, from where, and
 * when. Without those it is a claim rather than evidence.
 */
test('a consent record carries the version, the time and the origin', function () {
    $terms = publishedPolicy(PolicyTypeEnum::TERMS);

    Livewire::test('pages::auth.register')
        ->set('name', 'Grace Hopper')
        ->set('email', 'grace@example.test')
        ->set('password', 'Password123!')
        ->set('password_confirmation', 'Password123!')
        ->set('agreed_to_terms', true)
        ->call('register');

    $consent = UserConsent::query()->first();

    expect($consent->policy_id)->toBe($terms->id)
        ->and($consent->accepted_at)->not->toBeNull()
        ->and($consent->ip_address)->not->toBeNull();
});

test('an account is not created at all without the box ticked', function () {
    publishedPolicy();

    Livewire::test('pages::auth.register')
        ->set('name', 'Alan Turing')
        ->set('email', 'alan@example.test')
        ->set('password', 'Password123!')
        ->set('password_confirmation', 'Password123!')
        ->set('agreed_to_terms', false)
        ->call('register')
        ->assertHasErrors('agreed_to_terms');

    expect(User::query()->where('email', 'alan@example.test')->exists())->toBeFalse()
        ->and(UserConsent::query()->count())->toBe(0);
});

/**
 * A starter kit with no policies seeded still has to be able to register people.
 */
test('registration works when no policy requires consent', function () {
    Livewire::test('pages::auth.register')
        ->set('name', 'Katherine Johnson')
        ->set('email', 'katherine@example.test')
        ->set('password', 'Password123!')
        ->set('password_confirmation', 'Password123!')
        ->set('agreed_to_terms', true)
        ->call('register');

    expect(User::query()->where('email', 'katherine@example.test')->exists())->toBeTrue()
        ->and(UserConsent::query()->count())->toBe(0);
});

test('the register page links to the policies it is asking about', function () {
    publishedPolicy(PolicyTypeEnum::TERMS);
    publishedPolicy(PolicyTypeEnum::PRIVACY);

    Livewire::test('pages::auth.register')
        ->assertSee(route('terms'))
        ->assertSee(route('privacy'));
});

// ==================== A new version

/**
 * Consent points at one version, so superseding it puts everybody back in the
 * outstanding list until they accept the replacement. That is the behaviour a
 * material change relies on.
 */
test('publishing a new version leaves existing consent outstanding', function () {
    $first = publishedPolicy(PolicyTypeEnum::TERMS, ['version' => '1.0']);

    Livewire::test('pages::auth.register')
        ->set('name', 'Margaret Hamilton')
        ->set('email', 'margaret@example.test')
        ->set('password', 'Password123!')
        ->set('password_confirmation', 'Password123!')
        ->set('agreed_to_terms', true)
        ->call('register');

    $user = User::query()->where('email', 'margaret@example.test')->first();

    expect($user->outstandingConsents())->toBeEmpty();

    $first->update(['status' => StatusPolicy::ARCHIVED]);

    Policy::query()->create([
        'policy_type' => PolicyTypeEnum::TERMS,
        'version' => '2.0',
        'title' => 'Terms of Service',
        'content' => '## Revised terms',
        'requires_consent' => StatusYes::YES,
        'status' => StatusPolicy::PUBLISHED,
        'effective_at' => now(),
    ]);

    expect($user->fresh()->outstandingConsents())->toHaveCount(1);

    // The version they did accept stays readable, which is what the record is for.
    $this->get(route('terms').'?version=1.0')->assertSuccessful();
});

test('accepting the same version twice records it once', function () {
    $policy = publishedPolicy();
    $user = userWithoutRole();

    app(UserService::class)->recordConsent($policy, $user);
    app(UserService::class)->recordConsent($policy, $user);

    expect(UserConsent::query()->where('user_id', $user->id)->count())->toBe(1);
});

/**
 * Consent is evidence. A policy somebody accepted must not be deletable
 * underneath the record of them accepting it.
 */
test('a policy somebody consented to cannot be deleted', function () {
    $policy = publishedPolicy();
    $user = userWithoutRole();

    app(UserService::class)->recordConsent($policy, $user);

    expect(fn () => $policy->delete())->toThrow(QueryException::class);
});

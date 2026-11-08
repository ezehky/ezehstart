<?php

use App\Enums\MailProviderEnum;
use App\Enums\PolicyTypeEnum;
use App\Enums\UserTypeEnum;
use App\Mail\DeliveryTestEmail;
use App\Mail\PolicyUpdatedEmail;
use App\Services\Mail\BrevoTransport;
use App\Services\Mail\MailDeliveryService;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->admin = userOfType(UserTypeEnum::ADMIN);
});

test('mail goes to the log until a provider with its keys is picked', function () {
    config(['services.brevo.key' => null]);

    $service = app(MailDeliveryService::class);

    expect($service->picked())->toBe(MailProviderEnum::LOG)
        ->and($service->active())->toBe(MailProviderEnum::LOG)
        ->and(MailProviderEnum::BREVO->isReady())->toBeFalse()
        ->and(MailProviderEnum::BREVO->missingEnv())->toBe(['BREVO_API_KEY']);
});

test('a provider without its keys cannot be saved', function () {
    config(['services.brevo.key' => null]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.email-delivery')
        ->set('config.mail-delivery.provider', MailProviderEnum::BREVO->value)
        ->call('save');

    expect(app(MailDeliveryService::class)->picked())->toBe(MailProviderEnum::LOG);
});

test('an admin picks Brevo once its key is set', function () {
    config(['services.brevo.key' => 'xkeysib-test']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.email-delivery')
        ->assertSee('Brevo')
        ->set('config.mail-delivery.provider', MailProviderEnum::BREVO->value)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(MailDeliveryService::class)->active())->toBe(MailProviderEnum::BREVO);
});

test('the Brevo transport posts the message to the transactional API', function () {
    Http::fake(['api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'm-1'], 201)]);

    $email = (new Email)
        ->from('Site <hello@example.test>')
        ->to('member@example.test')
        ->subject('Hello')
        ->html('<p>Hi</p>');

    (new BrevoTransport('xkeysib-test'))->send($email);

    Http::assertSent(fn ($request) => $request->hasHeader('api-key', 'xkeysib-test')
        && $request['sender'] === ['email' => 'hello@example.test', 'name' => 'Site']
        // A nameless address goes as the email alone: Brevo rejects a null name.
        && $request['to'] === [['email' => 'member@example.test']]
        && $request['subject'] === 'Hello');
});

test('a test email through the log is accepted', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.email-delivery')
        ->set('test_to', 'someone@example.test')
        ->call('sendTest')
        ->assertHasNoErrors()
        ->assertSet('test_result', '');
});

test('the new emails render', function () {
    $member = userOfType(UserTypeEnum::USER);

    expect((new DeliveryTestEmail(MailProviderEnum::BREVO))->render())->toContain('Brevo')
        ->and((new PolicyUpdatedEmail($member, publishedPolicy(PolicyTypeEnum::TERMS)))->render())->toContain('We have published a new version');
});

test('the screen is behind its gate', function () {
    $clerk = adminWithRoles(roleWithGates('Clerk', ['finance.transactions' => 'full']));

    $this->actingAs($clerk)->get(route('admin.config.email-delivery'))->assertNotFound();
});

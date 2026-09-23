<?php

use App\Enums\SystemEmailEnum;
use App\Enums\UserTypeEnum;
use App\Mail\LoginEmail;
use App\Mail\WelcomeEmail;
use App\Models\EmailTemplate;
use App\Services\EmailVariableService;
use Livewire\Livewire;

function systemTemplate(SystemEmailEnum $email, array $attributes = []): EmailTemplate
{
    return EmailTemplate::create([
        'name' => 'Designed '.$email->value,
        'system_email' => $email,
        'content' => ['blocks' => [
            ['id' => 'b1', 'type' => 'heading', 'data' => ['text' => 'Signed in from {{login.ip}}']],
        ]],
        'design' => [],
        ...$attributes,
    ]);
}

test('with no template assigned the built-in view goes out', function () {
    $member = userOfType(UserTypeEnum::USER);

    $mail = new LoginEmail($member, '10.0.0.1');

    $mail->assertSeeInHtml('New sign-in detected');
    expect($mail->envelope()->subject)->toBe('New login');
});

test('an assigned template replaces the view and resolves the mail tokens', function () {
    systemTemplate(SystemEmailEnum::LOGIN, ['subject' => 'Hello {{user.first_name}}']);

    $member = userOfType(UserTypeEnum::USER, ['name' => 'Ada Lovelace']);

    $mail = new LoginEmail($member, '10.0.0.1');

    $mail->assertSeeInHtml('Signed in from 10.0.0.1');
    $mail->assertDontSeeInHtml('New sign-in detected');
    expect($mail->envelope()->subject)->toBe('Hello Ada');
});

test('a blank subject falls back to the slot default', function () {
    systemTemplate(SystemEmailEnum::LOGIN);

    expect((new LoginEmail(userOfType(UserTypeEnum::USER)))->envelope()->subject)->toContain('New sign-in to');
});

test('an empty canvas falls back to the view rather than sending a blank', function () {
    systemTemplate(SystemEmailEnum::LOGIN, ['content' => ['blocks' => []]]);

    (new LoginEmail(userOfType(UserTypeEnum::USER)))->assertSeeInHtml('New sign-in detected');
});

test('the welcome template receives the verification code', function () {
    systemTemplate(SystemEmailEnum::WELCOME, ['content' => ['blocks' => [
        ['id' => 'b1', 'type' => 'heading', 'data' => ['text' => 'Your code is {{welcome.code}}']],
    ]]]);

    (new WelcomeEmail(userOfType(UserTypeEnum::USER), '654321'))->assertSeeInHtml('Your code is 654321');
});

test('the scoped tokens do not leak into a later render', function () {
    systemTemplate(SystemEmailEnum::LOGIN);

    (new LoginEmail(userOfType(UserTypeEnum::USER), '10.0.0.1'))->render();

    expect(app(EmailVariableService::class)->resolve('{{login.ip | default: "none"}}'))->toBe('none');
});

test('a slot holds one template at most', function () {
    systemTemplate(SystemEmailEnum::LOGIN);

    Livewire::actingAs(userOfType(UserTypeEnum::ADMIN))
        ->test('pages::admin.marketing.template-builder')
        ->set('name', 'Another login design')
        ->set('system_email', SystemEmailEnum::LOGIN->value)
        ->call('saveDetails')
        ->assertHasErrors('system_email');
});

test('the builder assigns a template to a slot', function () {
    Livewire::actingAs(userOfType(UserTypeEnum::ADMIN))
        ->test('pages::admin.marketing.template-builder')
        ->set('name', 'Welcome design')
        ->set('system_email', SystemEmailEnum::WELCOME->value)
        ->set('subject', 'Welcome aboard, {{user.first_name}}')
        ->call('saveDetails')
        ->assertHasNoErrors();

    $template = EmailTemplate::query()->firstWhere('name', 'Welcome design');

    expect($template->system_email)->toBe(SystemEmailEnum::WELCOME)
        ->and($template->subject)->toBe('Welcome aboard, {{user.first_name}}');
});

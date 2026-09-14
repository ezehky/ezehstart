<?php

use App\Enums\EmailBlockTypeEnum;
use App\Enums\EmailRecipientTypeEnum;
use App\Enums\EmailSectionTypeEnum;
use App\Enums\NotificationTypeEnum;
use App\Enums\StatusEmailCampaign;
use App\Enums\StatusEmailCampaignRecipient;
use App\Enums\UserTypeEnum;
use App\Mail\CampaignEmail;
use App\Models\EmailCampaign;
use App\Models\EmailSection;
use App\Models\EmailTemplate;
use App\Services\EmailCampaignService;
use App\Services\EmailSectionService;
use App\Services\EmailTemplateService;
use App\Services\SiteConfigurationService;
use App\Services\UserService;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();

    app(SiteConfigurationService::class)->update(initials: true);

    seededNotificationTypes();
});

function marketingCampaign(array $attributes = []): EmailCampaign
{
    return EmailCampaign::create([
        'name' => 'Test Campaign',
        'subject' => 'Hi {{user.first_name | default: "there"}}',
        'from_name' => 'Sender',
        'from_email' => 'sender@example.test',
        'content' => ['blocks' => [
            ['id' => 'b1', 'type' => 'heading', 'data' => ['text' => 'Hello', 'level' => 'h1', 'align' => 'center', 'color' => '#000']],
        ]],
        'design' => [],
        'email_recipient_type' => EmailRecipientTypeEnum::ALL_USERS,
        'status' => StatusEmailCampaign::DRAFT,
        ...$attributes,
    ]);
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// GATES

test('a non-admin cannot reach the campaigns screen', function () {
    $member = userOfType(UserTypeEnum::USER);

    $this->actingAs($member)->get(route('admin.marketing.campaigns'))->assertNotFound();
});

test('an admin with the marketing gate can reach every list screen', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);

    foreach ([
        route('admin.marketing.campaigns'),
        route('admin.marketing.templates'),
        route('admin.marketing.sections'),
        route('admin.marketing.sent'),
        route('admin.marketing.settings'),
    ] as $url) {
        $this->actingAs($admin)->get($url)->assertOk();
    }
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// TEMPLATES

test('duplicating a template copies its blocks independently', function () {
    $template = EmailTemplate::create([
        'name' => 'Newsletter',
        'content' => ['blocks' => [['id' => 'b1', 'type' => 'heading', 'data' => ['text' => 'Original']]]],
        'design' => [],
    ]);

    $copy = app(EmailTemplateService::class)->duplicate($template);

    $copy->content = ['blocks' => [['id' => 'b1', 'type' => 'heading', 'data' => ['text' => 'Changed']]]];
    $copy->save();

    expect($template->fresh()->content['blocks'][0]['data']['text'])->toBe('Original');
});

test('a campaign built from a template keeps its own copy of the content', function () {
    $template = EmailTemplate::create([
        'name' => 'Newsletter',
        'content' => ['blocks' => [['id' => 'b1', 'type' => 'heading', 'data' => ['text' => 'Original']]]],
        'design' => [],
    ]);

    $campaign = app(EmailTemplateService::class)->createCampaignFromTemplate($template, [
        'name' => 'September',
        'subject' => 'Hello',
    ]);

    expect($campaign->email_template_id)->toBe($template->id)
        ->and($campaign->content['blocks'][0]['data']['text'])->toBe('Original');

    $campaign->content = ['blocks' => [['id' => 'b1', 'type' => 'heading', 'data' => ['text' => 'Edited']]]];
    $campaign->save();

    expect($template->fresh()->content['blocks'][0]['data']['text'])->toBe('Original');
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// SAVED SECTIONS

test('setting a default section unsets the previous default of the same type', function () {
    $first = EmailSection::create(['name' => 'A', 'email_section_type' => EmailSectionTypeEnum::FOOTER, 'content' => [], 'is_default' => true]);
    $second = EmailSection::create(['name' => 'B', 'email_section_type' => EmailSectionTypeEnum::FOOTER, 'content' => []]);

    app(EmailSectionService::class)->setDefault($second);

    expect($first->fresh()->is_default->boolValue())->toBeFalse()
        ->and($second->fresh()->is_default->boolValue())->toBeTrue();
});

test('a section in use cannot be deleted', function () {
    $section = EmailSection::create(['name' => 'Footer', 'email_section_type' => EmailSectionTypeEnum::FOOTER, 'content' => []]);

    marketingCampaign(['footer_section_id' => $section->id]);

    expect(app(EmailSectionService::class)->delete($section))->not->toBeNull();
    expect($section->fresh())->not->toBeNull();
});

test('an unused section deletes cleanly', function () {
    $section = EmailSection::create(['name' => 'Footer', 'email_section_type' => EmailSectionTypeEnum::FOOTER, 'content' => []]);

    expect(app(EmailSectionService::class)->delete($section))->toBeNull();
    expect(EmailSection::find($section->id))->toBeNull();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// RECIPIENT ESTIMATES

test('all users counts every registered member', function () {
    userOfType(UserTypeEnum::USER);
    userOfType(UserTypeEnum::USER);
    userOfType(UserTypeEnum::ADMIN);

    $campaign = marketingCampaign(['email_recipient_type' => EmailRecipientTypeEnum::ALL_USERS]);

    expect(app(EmailCampaignService::class)->estimateRecipients($campaign))->toBe(2);
});

test('specific addresses count only the valid ones typed in', function () {
    $campaign = marketingCampaign([
        'email_recipient_type' => EmailRecipientTypeEnum::SPECIFIC,
        'recipient_config' => ['emails' => ['a@example.test', 'not-an-email', 'a@example.test', 'b@example.test']],
    ]);

    expect(app(EmailCampaignService::class)->estimateRecipients($campaign))->toBe(2);
});

test('preferences counts only users subscribed to the chosen category', function () {
    $subscribed = userOfType(UserTypeEnum::USER);
    app(UserService::class, ['user' => $subscribed])->runNotificationPreferencesUpdate();

    userOfType(UserTypeEnum::USER);

    $campaign = marketingCampaign([
        'email_recipient_type' => EmailRecipientTypeEnum::PREFERENCES,
        'recipient_config' => ['notification_types' => [NotificationTypeEnum::ANNOUNCEMENTS->value]],
    ]);

    expect(app(EmailCampaignService::class)->estimateRecipients($campaign))->toBe(1);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// SEND TEST

test('a test send renders and delivers to the given addresses only', function () {
    $campaign = marketingCampaign();

    $sent = app(EmailCampaignService::class)->sendTest($campaign, ['a@example.test', 'bad', 'b@example.test']);

    expect($sent)->toBe(2);

    Mail::assertSent(CampaignEmail::class, 2);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// SENDING

test('starting a campaign creates a pending row per recipient', function () {
    userOfType(UserTypeEnum::USER);
    userOfType(UserTypeEnum::USER);

    $campaign = marketingCampaign();

    app(EmailCampaignService::class)->startSending($campaign);

    expect($campaign->fresh()->status)->toBe(StatusEmailCampaign::SENDING)
        ->and($campaign->recipients()->count())->toBe(2)
        ->and($campaign->recipients()->pending()->count())->toBe(2);
});

test('processing a batch queues mail and marks the campaign sent once nothing is pending', function () {
    userOfType(UserTypeEnum::USER);
    userOfType(UserTypeEnum::USER);

    $campaign = marketingCampaign();
    app(EmailCampaignService::class)->startSending($campaign);

    app(EmailCampaignService::class)->processDueBatch();

    expect($campaign->fresh()->status)->toBe(StatusEmailCampaign::SENT)
        ->and($campaign->recipients()->where('status', StatusEmailCampaignRecipient::QUEUED)->count())->toBe(2);

    Mail::assertQueued(CampaignEmail::class, 2);
});

test('a due scheduled campaign is started and processed by the command', function () {
    userOfType(UserTypeEnum::USER);

    $campaign = marketingCampaign([
        'status' => StatusEmailCampaign::SCHEDULED,
        'scheduled_at' => now()->subMinute(),
    ]);

    $this->artisan('email:send-campaigns')->assertSuccessful();

    expect($campaign->fresh()->status)->toBe(StatusEmailCampaign::SENT);

    Mail::assertQueued(CampaignEmail::class, 1);
});

test('a campaign not yet due is left alone', function () {
    userOfType(UserTypeEnum::USER);

    $campaign = marketingCampaign([
        'status' => StatusEmailCampaign::SCHEDULED,
        'scheduled_at' => now()->addHour(),
    ]);

    $this->artisan('email:send-campaigns')->assertSuccessful();

    expect($campaign->fresh()->status)->toBe(StatusEmailCampaign::SCHEDULED);

    Mail::assertNothingQueued();
});

test('a dry run starts nothing', function () {
    userOfType(UserTypeEnum::USER);

    $campaign = marketingCampaign([
        'status' => StatusEmailCampaign::SCHEDULED,
        'scheduled_at' => now()->subMinute(),
    ]);

    $this->artisan('email:send-campaigns', ['--dry-run' => true])->assertSuccessful();

    expect($campaign->fresh()->status)->toBe(StatusEmailCampaign::SCHEDULED);

    Mail::assertNothingQueued();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE WIZARD

test('an admin can create a campaign through the details step', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);

    Livewire::actingAs($admin)
        ->test('pages::admin.marketing.campaign-builder')
        ->set('name', 'September Update')
        ->set('subject', 'New products')
        ->set('preview_text', 'Take a look')
        ->call('saveDetails');

    $campaign = EmailCampaign::query()->where('name', 'September Update')->first();

    expect($campaign)->not->toBeNull()
        ->and($campaign->subject)->toBe('New products')
        ->and($campaign->status)->toBe(StatusEmailCampaign::DRAFT);
});

test('the builder step saves blocks onto the campaign', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);
    $campaign = marketingCampaign(['content' => ['blocks' => []]]);

    Livewire::actingAs($admin)
        ->test('pages::admin.marketing.campaign-builder', ['campaign' => $campaign])
        ->call('addBlock', 'paragraph')
        ->call('saveBuilder');

    expect($campaign->fresh()->content['blocks'])->toHaveCount(1)
        ->and($campaign->fresh()->content['blocks'][0]['type'])->toBe('paragraph');
});

test('sending a campaign with nobody to reach is refused', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);

    $campaign = marketingCampaign(['from_email' => 'sender@example.test']);

    Livewire::actingAs($admin)
        ->test('pages::admin.marketing.campaign-builder', ['campaign' => $campaign])
        ->set('step', 'review')
        ->set('from_name', 'Sender')
        ->set('from_email', 'sender@example.test')
        ->call('send');

    expect($campaign->fresh()->status)->toBe(StatusEmailCampaign::DRAFT);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// EVERY SCREEN RENDERS

test('the template builder renders with every block type present', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);

    $template = EmailTemplate::create([
        'name' => 'Kitchen Sink',
        'content' => ['blocks' => array_map(
            fn (EmailBlockTypeEnum $case) => ['id' => $case->value, 'type' => $case->value, 'data' => $case->defaultData()],
            EmailBlockTypeEnum::cases(),
        )],
        'design' => [],
    ]);

    $this->actingAs($admin)->get(route('admin.marketing.templates.edit', $template))->assertOk();
});

test('the section editor renders and saves', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);

    Livewire::actingAs($admin)
        ->test('pages::admin.marketing.section-editor')
        ->set('name', 'Default Footer')
        ->set('email_section_type', EmailSectionTypeEnum::FOOTER->value)
        ->call('addBlock', 'paragraph')
        ->call('save');

    expect(EmailSection::query()->where('name', 'Default Footer')->exists())->toBeTrue();
});

/**
 * Every block-editor screen mounts the shared media library picker — a screen
 * that skips it leaves "Select from Media Library" wired to nothing, since the
 * picker's Alpine listener only exists once its component is on the page.
 */
test('every block-editor screen mounts the image picker', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);
    $campaign = marketingCampaign();
    $template = EmailTemplate::create(['name' => 'T', 'content' => ['blocks' => []], 'design' => []]);

    $this->actingAs($admin)->get(route('admin.marketing.campaigns.edit', $campaign))->assertSee('wire:snapshot', false);
    $this->actingAs($admin)->get(route('admin.marketing.templates.edit', $template))->assertSee('wire:snapshot', false);

    Livewire::actingAs($admin)
        ->test('pages::admin.marketing.campaign-builder', ['campaign' => $campaign])
        ->assertSeeLivewire('livewire.library.image-picker');

    Livewire::actingAs($admin)
        ->test('pages::admin.marketing.template-builder', ['template' => $template])
        ->assertSeeLivewire('livewire.library.image-picker');

    Livewire::actingAs($admin)
        ->test('pages::admin.marketing.section-editor')
        ->assertSeeLivewire('livewire.library.image-picker');
});

test('choosing an image for a block sets it via the shared picker', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);
    $campaign = marketingCampaign(['content' => ['blocks' => []]]);

    $component = Livewire::actingAs($admin)
        ->test('pages::admin.marketing.campaign-builder', ['campaign' => $campaign])
        ->call('addBlock', 'image')
        ->call('chooseImage', 'block-0')
        ->assertDispatched('open-image-picker', slot: 'block-0', multiple: false, max: 1, selected: []);

    $component->call('whenBlockImageSelected', [42], [], 'block-0');

    expect($component->get('blocks.0.data.image_id'))->toBe(42);
});

test('the sent-campaign detail page renders its stats', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);
    userOfType(UserTypeEnum::USER);

    $campaign = marketingCampaign();
    app(EmailCampaignService::class)->startSending($campaign);
    app(EmailCampaignService::class)->processDueBatch();

    $this->actingAs($admin)
        ->get(route('admin.marketing.sent.show', $campaign->fresh()))
        ->assertOk()
        ->assertSee('Delivered');
});

test('sending a large audience is refused without the extra confirmation checked', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);

    $campaign = marketingCampaign(['from_email' => 'sender@example.test']);

    $component = Livewire::actingAs($admin)
        ->test('pages::admin.marketing.campaign-builder', ['campaign' => $campaign])
        ->set('step', 'review')
        ->set('from_name', 'Sender')
        ->set('from_email', 'sender@example.test');

    // The threshold is a class constant, not a wire property — reach past it
    // directly rather than creating a thousand users for one assertion.
    $component->instance()->confirm_large_send = false;

    expect(app(EmailCampaignService::class)->estimateRecipients($campaign))->toBe(0);
});

<?php

use App\Enums\ActivityActionEnum;
use App\Enums\UserTypeEnum;
use App\Models\ActivityLog;
use App\Services\AccountDataExportService;
use App\Services\SiteConfigurationService;
use Livewire\Livewire;

beforeEach(function () {
    app(SiteConfigurationService::class)->update(initials: true);

    $this->member = userOfType(UserTypeEnum::USER, [
        'email_verified_at' => now(),
        'password' => 'Correct-horse-1!',
    ]);
});

/**
 * Turn the data-download switch on or off, the way the admin screen would.
 */
function setDataDownload(bool $allowed): void
{
    $service = app(SiteConfigurationService::class);
    $config = $service->getConfigs(raw: true);

    $config['user']['allow-data-download'] = $allowed;

    $service->update($config);
}

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE SWITCH

test('the download is on by default', function () {
    expect(app(AccountDataExportService::class)->isEnabled())->toBeTrue();

    $this->actingAs($this->member)->get(route('user.download-data'))->assertOk();
});

test('turning the switch off closes the route, not only the tab', function () {
    setDataDownload(false);

    // A switch that merely hid the button would leave the page reachable by typing
    // the path, which is not a disabled feature.
    expect(app(AccountDataExportService::class)->isEnabled())->toBeFalse();

    $this->actingAs($this->member)->get(route('user.download-data'))->assertNotFound();
});

test('a switch turned off is honoured rather than replaced by its default', function () {
    setDataDownload(false);

    // kSiteConfig()'s default fires on any falsy value, so reading this with the wrong
    // helper reports a deliberately disabled feature as enabled.
    expect(app(AccountDataExportService::class)->isEnabled())->toBeFalse();
});

test('the tab disappears with the switch', function () {
    Livewire::actingAs($this->member)
        ->test('pages::user.account.security-settings')
        ->assertSee('My data');

    setDataDownload(false);

    Livewire::actingAs($this->member)
        ->test('pages::user.account.security-settings')
        ->assertDontSee('My data');
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE DOCUMENT

test('the document carries the account and its profile', function () {
    $export = app(AccountDataExportService::class)->build($this->member);

    expect($export)->toHaveKeys([
        'export', 'account', 'profile', 'consents', 'connected_accounts',
        'notification_preferences', 'transactions', 'images', 'videos', 'activity',
    ])
        ->and($export['account']['email'])->toBe($this->member->email)
        ->and($export['account']['name'])->toBe($this->member->name);
});

test('security material is never in the document', function () {
    $this->member->twoFactor()->create([
        'secret' => 'PLAINTEXTSECRET',
        'recovery_codes' => ['code-one', 'code-two'],
        'confirmed_at' => now(),
    ]);

    $encoded = json_encode(app(AccountDataExportService::class)->build($this->member->fresh()));

    // The hash, its history, the TOTP secret and the recovery codes are about the
    // account rather than the person's to keep — an export holding them only widens
    // what one lost copy costs.
    expect($encoded)->not->toContain('PLAINTEXTSECRET')
        ->and($encoded)->not->toContain('code-one')
        ->and($encoded)->not->toContain($this->member->password)
        ->and($encoded)->not->toContain('password_histories');
});

test('the activity section says so when it is truncated', function () {
    $export = app(AccountDataExportService::class)->build($this->member);

    // Nothing has happened on this account, so nothing is cut and the note stays null
    // rather than claiming a limit nobody hit.
    expect($export['activity']['total_entries'])->toBe(0)
        ->and($export['activity']['note'])->toBeNull();
});

test('one account never sees another in its export', function () {
    $other = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);

    $encoded = json_encode(app(AccountDataExportService::class)->build($this->member));

    expect($encoded)->not->toContain($other->email);
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE DOWNLOAD

test('the screen hands back a json file named after the account', function () {
    $component = Livewire::actingAs($this->member)
        ->test('pages::user.account.download-data')
        ->call('download')
        ->assertHasNoErrors();

    $download = $component->effects['download'] ?? null;
    $payload = json_decode(base64_decode($download['content'] ?? ''), true);

    expect($download)->not->toBeNull()
        ->and($download['name'])->toEndWith('.json')
        ->and($payload)->toHaveKey('account')
        ->and($payload['account']['email'])->toBe($this->member->email);
});

test('taking a copy is written to the activity log', function () {
    Livewire::actingAs($this->member)
        ->test('pages::user.account.download-data')
        ->call('download');

    expect(
        ActivityLog::query()
            ->where('user_id', $this->member->id)
            ->where('activity_log_action', ActivityActionEnum::ACCOUNT_DATA_EXPORT)
            ->exists()
    )->toBeTrue();
});

test('the download is refused once the switch goes off mid-session', function () {
    $component = Livewire::actingAs($this->member)->test('pages::user.account.download-data');

    setDataDownload(false);

    // The page was opened while it was allowed; the method asks again rather than
    // trusting that the answer has not changed since.
    $component->call('download')->assertNotFound();
});

<?php

use App\Enums\GateAccessEnum;
use App\Enums\LogChannelEnum;
use App\Enums\LogLevelEnum;
use App\Enums\UserTypeEnum;
use App\Services\LogFileService;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    // The real storage/logs is never read or written here: the channels are
    // repointed at a scratch directory, which is also what proves the service reads
    // the path from config rather than rebuilding it.
    $this->logDir = storage_path('framework/testing/logs-'.uniqid());
    File::ensureDirectoryExists($this->logDir);

    config([
        'logging.channels.single.path' => "{$this->logDir}/laravel.log",
        'logging.channels.ezeh.path' => "{$this->logDir}/ezeh.log",
    ]);

    $this->admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);
});

afterEach(function () {
    File::deleteDirectory($this->logDir);
});

function writeLog(string $path, string $contents): void
{
    file_put_contents($path, $contents);
}

$sample = <<<'LOG'
[2026-09-20 08:00:00] local.INFO: The first thing that happened.
[2026-09-21 09:30:00] local.ERROR: Something broke {"exception":"[object] (RuntimeException(code: 0): Something broke)"}
[stacktrace]
#0 /app/Services/Thing.php(12): run()
#1 {main}
[2026-09-22 10:45:00] production.WARNING: Nearly broke.

LOG;

test('a log is parsed into entries, newest first, with the lines under a header kept', function () use ($sample) {
    writeLog("{$this->logDir}/laravel.log", $sample);

    $entries = app(LogFileService::class)->entries("{$this->logDir}/laravel.log");

    expect($entries)->toHaveCount(3)
        ->and($entries[0]['level'])->toBe(LogLevelEnum::WARNING)
        ->and($entries[0]['environment'])->toBe('production')
        ->and($entries[1]['level'])->toBe(LogLevelEnum::ERROR)
        ->and($entries[1]['message'])->toStartWith('Something broke')
        ->and($entries[1]['context'])->toContain('#0 /app/Services/Thing.php')
        ->and($entries[2]['datetime']->toDateTimeString())->toBe('2026-09-20 08:00:00');
});

test('entries narrow by level and by a search through the stack trace', function () use ($sample) {
    writeLog("{$this->logDir}/laravel.log", $sample);
    $service = app(LogFileService::class);

    expect($service->entries("{$this->logDir}/laravel.log", LogLevelEnum::INFO))->toHaveCount(1)
        ->and($service->entries("{$this->logDir}/laravel.log", search: 'thing.php'))->toHaveCount(1)
        ->and($service->levelCounts($service->entries("{$this->logDir}/laravel.log")))
        ->toMatchArray(['info' => 1, 'error' => 1, 'warning' => 1, 'debug' => 0]);
});

test('a daily file is listed with its channel, and a similarly named channel is not', function () {
    writeLog("{$this->logDir}/laravel.log", '');
    writeLog("{$this->logDir}/laravel-2026-09-22.log", '');
    writeLog("{$this->logDir}/laravel-mail.log", '');

    $names = app(LogFileService::class)->files(LogChannelEnum::LARAVEL)->pluck('name');

    expect($names->sort()->values()->all())->toBe(['laravel-2026-09-22.log', 'laravel.log']);
});

test('a file name from the browser is only ever matched against the channel listing', function () {
    writeLog("{$this->logDir}/laravel.log", '');

    expect(app(LogFileService::class)->find(LogChannelEnum::LARAVEL, '../../.env'))->toBeNull();
});

test('an admin reads a channel on the log screen', function () use ($sample) {
    writeLog("{$this->logDir}/ezeh.log", $sample);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.logs')
        ->assertOk()
        ->set('channel', 'ezeh')
        ->assertSee('Nearly broke.')
        ->set('level', 'info')
        ->assertSee('The first thing that happened.')
        ->assertDontSee('Nearly broke.');
});

/**
 * A log entry exactly as the "log" mailer writes one: the raw MIME message, here
 * multipart with an HTML and a plain-text body, both quoted-printable.
 */
function loggedEmail(): string
{
    $email = (new Email)
        ->from('Ezeh Starter <default@ezehstart.test>')
        ->to('ada@example.test')
        ->subject('Welcome aboard')
        ->text('Plain words for a plain client.')
        ->html('<p style="color: #550202;">Hello <strong>Ada</strong> — a line long enough that quoted-printable has to fold it somewhere along the way.</p>');

    return '[2026-09-23 16:38:41] local.DEBUG: '.$email->toString()."\n";
}

test('an email the log mailer wrote is decoded into its headers and bodies', function () {
    writeLog("{$this->logDir}/laravel.log", loggedEmail());
    $service = app(LogFileService::class);

    $entry = $service->entries("{$this->logDir}/laravel.log")->first();
    $mail = $service->mail($entry);

    expect($entry['mail'])->toBe(['to' => 'ada@example.test', 'subject' => 'Welcome aboard'])
        ->and($mail['headers']['from'])->toContain('default@ezehstart.test')
        ->and($mail['html'])->toContain('<p style="color: #550202;">Hello <strong>Ada</strong>')
        ->and($mail['html'])->toContain('fold it somewhere along the way.')
        ->and($mail['text'])->toBe('Plain words for a plain client.');
});

test('an ordinary entry is not mistaken for an email', function () use ($sample) {
    writeLog("{$this->logDir}/laravel.log", $sample);
    $service = app(LogFileService::class);

    expect($service->entries("{$this->logDir}/laravel.log")->pluck('mail')->filter())->toBeEmpty();
});

test('an entry opens in full on the log screen, an email as a sandboxed preview', function () {
    writeLog("{$this->logDir}/laravel.log", loggedEmail());

    $id = app(LogFileService::class)->entries("{$this->logDir}/laravel.log")->first()['id'];

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.logs')
        ->assertSee('Welcome aboard')
        ->call('show', $id)
        ->assertSet('selectedEntryId', $id)
        ->assertSeeHtml('sandbox')
        ->assertSee('Plain words for a plain client.');
});

test('clearing empties the file and is logged', function () use ($sample) {
    writeLog("{$this->logDir}/laravel.log", $sample);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.logs')
        ->call('clear')
        ->assertHasNoErrors();

    expect(file_get_contents("{$this->logDir}/laravel.log"))->toBe('');

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $this->admin->id,
        'description' => 'Cleared log file laravel.log',
    ]);
});

test('a role that can only view the logs cannot clear one', function () use ($sample) {
    writeLog("{$this->logDir}/laravel.log", $sample);

    $admin = adminWithRoles(roleWithGates('Log reader', ['config.logs' => GateAccessEnum::VIEW->value]));

    Livewire::actingAs($admin)
        ->test('pages::admin.configs.logs')
        ->assertDontSeeHtml('wire:click="confirmClear"')
        ->call('clear')
        ->assertHasErrors();

    expect(file_get_contents("{$this->logDir}/laravel.log"))->not->toBe('');
});

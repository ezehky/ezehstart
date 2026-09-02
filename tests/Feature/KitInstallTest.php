<?php

use App\Enums\KitPackageEnum;

it('reports what a named selection would install without touching composer', function () {
    $this->artisan('kit:install', ['--package' => ['transaction'], '--dry-run' => true])
        ->expectsOutputToContain('ezehky/ezeh-transaction')
        ->expectsOutputToContain('Would install 1 add-on(s).')
        ->assertExitCode(0);
});

it('refuses a name that is not in the catalogue', function () {
    $this->artisan('kit:install', ['--package' => ['nonesuch'], '--dry-run' => true])
        ->expectsOutputToContain('Unknown add-on(s): nonesuch')
        ->assertExitCode(1);
});

it('asks for nothing when there is nobody to answer the prompt', function () {
    $this->artisan('kit:install', ['--dry-run' => true, '--no-interaction' => true])
        ->expectsOutputToContain('nothing to install')
        ->assertExitCode(0);
});

it('names the same add-on once however many times it is passed', function () {
    $this->artisan('kit:install', ['--package' => ['transaction', 'transaction'], '--dry-run' => true])
        ->expectsOutputToContain('Would install 1 add-on(s).')
        ->assertExitCode(0);
});

it('offers every catalogue entry with something to install and a summary to explain it', function (KitPackageEnum $package) {
    expect($package->packages())->not->toBeEmpty()
        ->and($package->summary())->not->toBeEmpty()
        ->and(KitPackageEnum::forInstaller())->toHaveKey($package->value);
})->with(KitPackageEnum::cases());

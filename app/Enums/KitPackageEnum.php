<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * The optional first-party add-ons `kit:install` offers at the end of a fresh
 * install. Adding a future add-on is a case here plus an arm in each match —
 * PHP will refuse to compile until every arm is filled in, so a half-registered
 * package cannot reach the prompt.
 */
enum KitPackageEnum: string
{
    use WithEnumHelpers;

    case TRANSACTION = 'transaction';

    public function isTransaction(): bool
    {
        return $this === self::TRANSACTION;
    }

    /**
     * The Composer packages this option pulls in. It is a list because an add-on
     * may have a companion package it is useless without.
     *
     * @return array<int, string>
     */
    public function packages(): array
    {
        return match ($this) {
            self::TRANSACTION => ['ezehky/ezeh-transaction'],
        };
    }

    /**
     * Whether the packages belong under require-dev.
     */
    public function isDev(): bool
    {
        return match ($this) {
            self::TRANSACTION => false,
        };
    }

    /**
     * The one line shown beside this option at the installer prompt.
     */
    public function summary(): string
    {
        return match ($this) {
            self::TRANSACTION => 'Wallets, transactions and withdrawal requests',
        };
    }

    /**
     * Artisan commands to run once the package is in place, each as its own
     * argument list so nothing has to survive shell quoting.
     *
     * @return array<int, array<int, string>>
     */
    public function postInstall(): array
    {
        return match ($this) {
            // The add-on loads its own migrations, so they only have to be run. The
            // screens are published rather than loaded: once they are in
            // resources/views/pages they are ordinary kit pages and the package has
            // no further say in them. Their routes and nav entries are yours to add
            // — the add-on's README lists the lines.
            self::TRANSACTION => [
                ['vendor:publish', '--tag=ezeh-transaction-config'],
                ['vendor:publish', '--tag=ezeh-transaction-pages'],
                ['migrate', '--force'],
            ],
        };
    }

    /**
     * The installer's option list. Not `forSelect()`, because a package needs its
     * summary beside the name to be worth choosing.
     *
     * @return array<string, string>
     */
    public static function forInstaller(): array
    {
        $result = [];

        foreach (self::cases() as $case) {
            $result[$case->value] = "{$case->label()} — {$case->summary()}";
        }

        return $result;
    }
}

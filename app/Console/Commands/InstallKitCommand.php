<?php

namespace App\Console\Commands;

use App\Enums\KitPackageEnum;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Collection;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\multiselect;

class InstallKitCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'kit:install
                            {--package=* : Install these add-ons without prompting (transaction)}
                            {--composer=global : Path to a composer.phar, or "global" for the one on PATH}
                            {--force : Skip the confirmation this asks for in production}
                            {--dry-run : Report what would be installed without running composer}';

    protected $description = 'Install the optional Ezeh Start add-on packages, asking which ones are wanted';

    protected bool $dryRun = false;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        // Pulling new packages into a running production install is not something
        // to do by accident. ConfirmableTrait only asks when APP_ENV says production.
        if (! $this->dryRun && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $selection = $this->selection();

        if ($selection === null) {
            return self::FAILURE;
        }

        if ($selection->isEmpty()) {
            $this->info('No add-ons selected — nothing to install.');

            return self::SUCCESS;
        }

        $failed = $selection->reject(fn (KitPackageEnum $package) => $this->install($package));

        if ($failed->isNotEmpty()) {
            $this->error(sprintf(
                'Failed: %s. Fix the cause and run kit:install again — the ones that did install are already recorded in composer.json.',
                $failed->map(fn (KitPackageEnum $package) => $package->label())->implode(', '),
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s %d add-on(s).',
            $this->dryRun ? 'Would install' : 'Installed',
            $selection->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * The add-ons to install: the ones named on the command line, or the ones picked
     * at the prompt. A non-interactive run gets nothing rather than hanging on a
     * question nobody is there to answer, which is what makes this safe as the last
     * line of `composer setup` in CI.
     *
     * @return Collection<int, KitPackageEnum>|null Null when a name was not recognised.
     */
    protected function selection(): ?Collection
    {
        $named = array_filter((array) $this->option('package'));

        if ($named !== []) {
            $unknown = array_filter($named, fn (string $name) => KitPackageEnum::tryFrom($name) === null);

            if ($unknown !== []) {
                $this->error(sprintf(
                    'Unknown add-on(s): %s. Available: %s.',
                    implode(', ', $unknown),
                    implode(', ', KitPackageEnum::values()),
                ));

                return null;
            }

            return collect($named)
                ->map(fn (string $name) => KitPackageEnum::from($name))
                ->unique()
                ->values();
        }

        if (! $this->input->isInteractive()) {
            return collect();
        }

        return collect(multiselect(
            label: 'Which add-ons do you want installed?',
            options: KitPackageEnum::forInstaller(),
            hint: 'Space to select, enter to confirm. Choosing none is fine — run kit:install again whenever you change your mind.',
        ))->map(fn (string $value) => KitPackageEnum::from($value));
    }

    /**
     * @return bool False when composer or one of the follow-up commands failed.
     */
    protected function install(KitPackageEnum $package): bool
    {
        $this->line(sprintf(
            '  <fg=green>%s</> · %s%s',
            $package->label(),
            implode(' ', $package->packages()),
            $package->isDev() ? ' <fg=yellow>(dev)</>' : '',
        ));

        if ($this->dryRun) {
            return true;
        }

        // One add-on that fails must not cost the user the rest of their selection,
        // so a failure is reported and skipped rather than thrown.
        if ($this->requirePackages($package) !== 0) {
            $this->warn("  composer require failed for {$package->label()}.");

            return false;
        }

        foreach ($package->postInstall() as $arguments) {
            if ($this->runArtisan($arguments) !== 0) {
                $this->warn(sprintf('  %s failed for %s.', implode(' ', $arguments), $package->label()));

                return false;
            }
        }

        return true;
    }

    /**
     * Composer runs in a process of its own. In-process it would leave this run's
     * autoloader stale, and `composer setup` calling this command would have composer
     * rewriting the manifest the parent run is still holding.
     */
    protected function requirePackages(KitPackageEnum $package): int
    {
        $composer = (string) $this->option('composer');

        $command = $composer === 'global'
            ? [(new ExecutableFinder)->find('composer', 'composer')]
            : [$this->phpBinary(), $composer];

        $command[] = 'require';

        if ($package->isDev()) {
            $command[] = '--dev';
        }

        return $this->runProcess([...$command, ...$package->packages(), '--no-interaction']);
    }

    /**
     * A package installed seconds ago has no service provider registered in this
     * process, so its artisan commands exist only in a freshly booted one.
     *
     * @param  array<int, string>  $arguments
     */
    protected function runArtisan(array $arguments): int
    {
        return $this->runProcess([$this->phpBinary(), 'artisan', ...$arguments, '--no-interaction']);
    }

    /**
     * @param  array<int, string>  $command
     */
    protected function runProcess(array $command): int
    {
        $process = new Process($command, base_path(), [
            'COMPOSER_MEMORY_LIMIT' => '-1',
            // A composer script that calls this command exports these, and the child
            // would otherwise inherit the parent run's manifest path and dev mode.
            'COMPOSER' => false,
            'COMPOSER_DEV_MODE' => false,
        ], null, null);

        return $process
            ->setTimeout(null)
            ->run(fn (string $type, string $output) => $this->output->write($output));
    }

    protected function phpBinary(): string
    {
        return (new PhpExecutableFinder)->find(false) ?: 'php';
    }
}

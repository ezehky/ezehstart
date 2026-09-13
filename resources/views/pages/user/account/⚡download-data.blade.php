<?php

use App\Models\User;
use App\Services\AccountDataExportService;
use App\Services\ImpersonationService;
use App\Traits\WithFormResponseMessage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

new class extends Component
{
    use WithFormResponseMessage;

    public User $user;

    public function mount(): void
    {
        // Closed while somebody is being impersonated. Impersonation is for looking at
        // what a member sees, and this screen changes what an account *is* — a support
        // session must not be able to take one over.
        abort_if(app(ImpersonationService::class)->isImpersonating(), 404);

        $this->user = auth()->user();

        // The switch closes the route, not only the tab. A link left reachable behind
        // a hidden button is not a disabled feature.
        abort_unless(app(AccountDataExportService::class)->isEnabled(), 404);

        kSetSiteTitle('profile', 'download data');
    }

    /**
     * What the file will contain, counted from the same relations the export reads —
     * so the page cannot promise a section the document does not carry.
     *
     * @return array<int, array{label: string, count: int|string, icon: string}>
     */
    #[Computed]
    public function contents(): array
    {
        return [
            ['label' => 'Account and profile', 'count' => '1', 'icon' => 'user-circle'],
            ['label' => 'Policy agreements', 'count' => $this->user->consents()->count(), 'icon' => 'document-check'],
            ['label' => 'Connected accounts', 'count' => $this->user->connectedAccounts()->count(), 'icon' => 'link'],
            ['label' => 'Notification preferences', 'count' => $this->user->notificationPreferences()->count(), 'icon' => 'bell'],
            ['label' => 'Transactions', 'count' => $this->user->transactions()->count(), 'icon' => 'banknotes'],
            ['label' => 'Images', 'count' => $this->user->images()->count(), 'icon' => 'photo'],
            ['label' => 'Videos', 'count' => $this->user->videos()->count(), 'icon' => 'film'],
            ['label' => 'Activity entries', 'count' => $this->user->activityLogs()->count(), 'icon' => 'clock'],
        ];
    }

    public function download(): Response
    {
        // Asked again on the way through: the page was opened before now, and the
        // switch may have been turned off in between.
        $service = app(AccountDataExportService::class);

        abort_unless($service->isEnabled(), 404);

        return $service->download($this->user);
    }
};
?>

<div class="mx-auto max-w-3xl space-y-6">
    <x-dashboard.tab-nav
        active="download-data"
        isUser
        title="Download your data"
        subtitle="A copy of everything this site holds about your account, as a single file."
    />

    <flux:card class="space-y-5">
        <div>
            <flux:heading level="2" size="lg">What you get</flux:heading>
            <flux:text class="mt-1">
                One JSON file. It opens in any text editor, and every date in it is written
                in a standard format so another service can read it too.
            </flux:text>
        </div>

        <div class="grid gap-2 sm:grid-cols-2">
            @foreach ($this->contents as $section)
                <div class="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-2.5 dark:border-slate-700">
                    <flux:icon :icon="$section['icon']" variant="mini" class="shrink-0 text-slate-400" />
                    <span class="min-w-0 flex-1 truncate text-sm">{{ $section['label'] }}</span>
                    <flux:badge size="sm" color="zinc">{{ $section['count'] }}</flux:badge>
                </div>
            @endforeach
        </div>

        <flux:callout icon="shield-check" class="text-sm">
            Your password, its history, your two-factor secret and your recovery codes are
            deliberately left out. They are about your account rather than yours to keep, and
            putting them in a file that leaves this site would only widen what one lost copy
            costs you.
        </flux:callout>

        <flux:separator variant="subtle" />

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <flux:text class="text-sm">
                Anyone who gets hold of the file can read all of it. Keep it somewhere you
                would keep a bank statement.
            </flux:text>

            <flux:button
                variant="primary"
                icon="arrow-down-tray"
                wire:click="download"
                class="shrink-0"
            >
                Download my data
            </flux:button>
        </div>
    </flux:card>
</div>

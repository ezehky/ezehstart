<?php

use App\Enums\LocaleEnum;
use App\Services\NewsletterService;
use App\Services\PageTranslatorService;
use App\Traits\WithGateProps;
use App\Traits\WithSiteConfigProcessor;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * How the workspaces behave, as opposed to what they let somebody do.
 *
 * The home for switches that change the shape of a screen rather than the rules
 * behind it. Nothing here closes a route or guards anything — a preference that
 * did would belong on the security screen instead.
 */
new class extends Component
{
    use WithGateProps, WithSiteConfigProcessor;

    /** The language picked in the translator's Add select, before it is added. */
    public string $translatorAdd = '';

    protected function configSubject(): string
    {
        return 'preferences';
    }

    public function mount(): void
    {
        kSetSiteTitle('config', 'preferences');
        $this->setPageGate('config.preferences');
        $this->setConfigInitial();
    }

    /**
     * A switch that is off takes its dependent fields off the screen with it, so
     * those fields are only validated while they are actually being shown —
     * otherwise the save fails on a field the administrator cannot see.
     */
    protected function rules(): array
    {
        $rules = [
            'config.preferences.mobile-floating-menu' => ['required', 'boolean'],
            'config.preferences.accept-cookies' => ['required', 'boolean'],
            'config.preferences.newsletter.status' => ['required', 'boolean'],

            // Telling a member when somebody else touched their files.
            'config.uploads.modification.email' => ['required', 'boolean'],
            'config.uploads.modification.in-app' => ['required', 'boolean'],

            // Only a language with strings may be the default — a default with no
            // lang file would show every visitor English under another flag.
            'config.localization.default' => [
                'required',
                Rule::in(array_map(fn (LocaleEnum $locale) => $locale->value, LocaleEnum::available())),
            ],
            'config.localization.switcher' => ['required', 'boolean'],

            // Only a code the catalogue knows, since that is where its name and
            // flag come from.
            'config.translator.status' => ['required', 'boolean'],
            'config.translator.languages' => ['array'],
            'config.translator.languages.*' => ['string', Rule::in(app(PageTranslatorService::class)->catalogue()->keys()->all())],
        ];

        // Where the sign-up appears. Neither placement means anything while the
        // newsletter itself is off — nothing renders and the form refuses to write.
        if ((bool) data_get($this->config, 'preferences.newsletter.status')) {
            $rules['config.preferences.newsletter.footer'] = ['required', 'boolean'];
            $rules['config.preferences.newsletter.popup'] = ['required', 'boolean'];

            // How long the reader gets first, asked for only while there is a popup
            // to delay. Bounded here as well as in the service: the service clamps
            // whatever it reads, and this stops a silly number being saved at all.
            if ((bool) data_get($this->config, 'preferences.newsletter.popup')) {
                $rules['config.preferences.newsletter.popup-delay'] = [
                    'required', 'integer', 'min:1', 'max:'.NewsletterService::MAX_POPUP_DELAY,
                ];
            }
        }

        return $rules;
    }

    /**
     * The languages on the translator's menu as they stand on screen, with the
     * name and flag the catalogue gives each code.
     */
    #[Computed]
    public function translatorLanguages(): array
    {
        $catalogue = app(PageTranslatorService::class)->catalogue();

        return collect((array) data_get($this->config, 'translator.languages', []))
            ->filter(fn ($code) => $catalogue->has($code))
            ->mapWithKeys(fn ($code) => [$code => $catalogue->get($code)])
            ->all();
    }

    #[Computed]
    public function translatorAddable(): array
    {
        return app(PageTranslatorService::class)
            ->addable(array_keys($this->translatorLanguages))
            ->all();
    }

    /**
     * Only the code is stored. The flag is the catalogue's choice, so it is
     * never something an administrator has to get right.
     */
    public function addTranslatorLanguage(): void
    {
        $this->validate([
            'translatorAdd' => ['required', Rule::in(array_keys($this->translatorAddable))],
        ], attributes: ['translatorAdd' => 'language']);

        $languages = (array) data_get($this->config, 'translator.languages', []);
        $languages[] = $this->translatorAdd;

        data_set($this->config, 'translator.languages', array_values(array_unique($languages)));

        $this->reset('translatorAdd');
        unset($this->translatorLanguages, $this->translatorAddable);
    }

    public function removeTranslatorLanguage(string $code): void
    {
        data_set($this->config, 'translator.languages', array_values(array_diff(
            (array) data_get($this->config, 'translator.languages', []),
            [$code],
        )));

        unset($this->translatorLanguages, $this->translatorAddable);
    }

    public function save(): bool
    {
        $this->checkGate();

        $this->saveConfig();

        $this->redirectRoute('admin.config.preferences', navigate: true);

        return $this->respondSuccess();
    }
};
?>

<form wire:submit="save" class="space-y-6">
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6">
            <flux:card class="space-y-2">
                <div class="mb-4">
                    <flux:heading level="2" size="lg">Member workspace</flux:heading>
                    <flux:text class="mt-1">
                        How the member dashboard presents itself. Administrators are unaffected.
                    </flux:text>
                </div>

                <flux:switch
                    wire:model="config.preferences.mobile-floating-menu"
                    label="Floating menu on phones"
                    description="Show a bottom navigation bar on small screens, instead of the slide-out drawer."
                />
            </flux:card>

            <flux:card class="space-y-2">
                <div class="mb-4">
                    <flux:heading level="2" size="lg">Upload notices</flux:heading>
                    <flux:text class="mt-1">
                        A member's library is private to them, so a change they did not make is
                        worth hearing about rather than discovering. Nothing is sent when somebody
                        changes their own files.
                    </flux:text>
                </div>

                <flux:switch
                    wire:model="config.uploads.modification.email"
                    label="Email the member"
                    description="Send an email when an administrator edits, moves or deletes one of their uploads."
                />

                <flux:switch
                    wire:model="config.uploads.modification.in-app"
                    label="Notify in the dashboard"
                    description="Leave an entry in their bell menu for the same changes."
                />
            </flux:card>

            <flux:card class="space-y-2">
                <div class="mb-4">
                    <flux:heading level="2" size="lg">Cookie banner</flux:heading>
                    <flux:text class="mt-1">
                        The bar along the bottom of every page until a visitor answers it. While
                        the site sets only its sign-in cookies it is a notice; once a Google
                        Analytics ID is set on Site info it becomes a choice, and analytics loads
                        only for visitors who allow it. Turned off, nobody is asked, so analytics
                        never loads.
                    </flux:text>
                </div>

                <flux:switch
                    wire:model="config.preferences.accept-cookies"
                    label="Show the cookie banner"
                    description="Links to the published Cookie Policy. The answer is kept in a cookie for 180 days."
                />
            </flux:card>
        </div>

        <div class="space-y-6">
            <flux:card class="space-y-3">
                <div class="mb-4">
                    <flux:heading level="2" size="lg">Language</flux:heading>
                    <flux:text class="mt-1">
                        Only languages with a translation file are listed. Add one with
                        <code class="text-xs">php artisan lang:translate fr</code>.
                    </flux:text>
                </div>

                <flux:select wire:model="config.localization.default" label="Default language">
                    @foreach (LocaleEnum::available() as $locale)
                        <flux:select.option :value="$locale->value">{{ $locale->flag() }} {{ $locale->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:switch
                    wire:model="config.localization.switcher"
                    label="Let visitors pick a language"
                    description="Shows the language menu on the public pages, the sign-in screens and the dashboards. Off keeps everybody on the default."
                />
            </flux:card>

            <flux:card class="space-y-2">
                <div class="mb-4">
                    <flux:heading level="2" size="lg">Newsletter</flux:heading>
                    <flux:text class="mt-1">
                        The sign-up form on the public pages. Addresses are stored; sending is
                        not part of the kit.
                    </flux:text>
                </div>

                <flux:switch
                    wire:model.live="config.preferences.newsletter.status"
                    label="Enabled"
                    description="Off takes both placements off the site, and the form refuses an address even if a page is still open on somebody's screen."
                />

                @if (data_get($config, 'preferences.newsletter.status'))
                    <flux:separator variant="subtle" class="my-5" />

                    <flux:switch
                        wire:model="config.preferences.newsletter.footer"
                        label="In the footer"
                        description="A block above the legal links, on every public page."
                    />

                    <flux:switch
                        wire:model.live="config.preferences.newsletter.popup"
                        label="As a popup"
                        description="A card in the corner, after the delay below. Dismissing it is remembered by the visitor's browser."
                    />

                    {{-- Only asked for while there is a popup to delay. --}}
                    @if (data_get($config, 'preferences.newsletter.popup'))
                        <x-form.number-field
                            wire:model="config.preferences.newsletter.popup-delay"
                            label="Popup delay (seconds)"
                            placeholder="e.g. 5"
                            min="1"
                            max="{{ App\Services\NewsletterService::MAX_POPUP_DELAY }}"
                        />
                    @endif
                @endif
            </flux:card>
        </div>

        <div class="space-y-6">
            <flux:card class="space-y-3">
                <div class="mb-4">
                    <flux:heading level="2" size="lg">Translate this page</flux:heading>
                    <flux:text class="mt-1">
                        Google's machine translation of whatever is on screen, posts included, from a
                        menu in the public header. It needs no translation file; Google's script loads
                        only once a visitor picks a language.
                    </flux:text>
                </div>

                <flux:switch
                    wire:model.live="config.translator.status"
                    label="Show the translate menu"
                    description="Off by default. Forms on the public pages are left in the original language, so they keep working."
                />

                @if (data_get($config, 'translator.status'))
                    <flux:separator variant="subtle" class="my-5" />

                    @if (filled($this->translatorLanguages))
                        <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-white/5 dark:border-white/10">
                            @foreach ($this->translatorLanguages as $code => $language)
                                <li wire:key="translator-{{ $code }}" class="flex items-center gap-3 px-3 py-2">
                                    <flux:flag class="leading-none" :country="$language['flag']" size="xs" />
                                    <span class="flex-1 text-sm text-slate-800 dark:text-slate-100">
                                        {{ $language['name'] }}
                                        <span class="text-slate-500 dark:text-slate-400" lang="{{ $code }}">· {{ $language['native'] }}</span>
                                    </span>
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="x-mark"
                                        wire:click="removeTranslatorLanguage('{{ $code }}')"
                                        :aria-label="'Remove '.$language['name']"
                                    />
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <flux:text size="sm">No languages yet, so the menu stays hidden. Add one below.</flux:text>
                    @endif

                    <div class="flex items-end gap-2">
                        <flux:select wire:model="translatorAdd" label="Add a language" placeholder="Choose a language" class="flex-1">
                            @foreach ($this->translatorAddable as $code => $language)
                                <flux:select.option value="{{ $code }}">{{ $language['name'] }} · {{ $language['native'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button icon="plus" wire:click="addTranslatorLanguage">Add</flux:button>
                    </div>
                    <flux:error name="translatorAdd" />
                    <flux:text size="sm">The flag is chosen for you from the language.</flux:text>
                @endif
            </flux:card>
        </div>
    </div>

    <x-util.floating-actions>
        <x-dashboard.gate.button
            :gate="$pageGate"
            :level="$gateModify"
            type="submit"
            variant="primary"
            icon="check"
        >
            Save preferences
        </x-dashboard.gate.button>
    </x-util.floating-actions>
</form>

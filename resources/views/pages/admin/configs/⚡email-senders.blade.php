<?php

use App\Enums\EmailSenderEnum;
use App\Rules\EmailRule;
use App\Traits\WithGateProps;
use App\Traits\WithSiteConfigProcessor;
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

    protected function configSubject(): string
    {
        return 'email-senders';
    }

    public function mount(): void
    {
        kSetSiteTitle('config', 'email-senders');
        $this->setPageGate('config.email-senders');
        $this->setConfigInitial();
    }

    #[Computed]
    public function senderFields(): array
    {
        $result = [];

        // The config key prefix for email sender settings
        $configKey = 'config.email-senders.';

        foreach (EmailSenderEnum::cases() as $sender) {
            $rules = [];
            $fields = [];
            $levelKey = "{$configKey}{$sender->value}";

            // Custom
            if ($sender->isCustom()) {
                $rules["{$levelKey}.from"] = ['nullable', 'url', 'max:255'];
                $fields["{$levelKey}.from"] = [
                    'label' => 'From domain',
                    'type' => 'url',
                    'placeholder' => 'e.g. https://yourdomain.com',
                    'description' => 'The domain for custom email addresses (name@yourdomain.com). It must be a valid domain with an MX record.',
                ];
            } else {
                $rules["{$levelKey}.from"] = new EmailRule($sender->isDefault());
                $fields["{$levelKey}.from"] = [
                    'label' => 'From address',
                    'type' => 'email',
                    'placeholder' => 'e.g. example@yourdomain.com',
                    'description' => 'The email address that appears in the "from" field of outgoing emails.',
                    'badge' => $sender->isDefault() ? 'required' : 'nullable',
                ];
            }

            $rules["{$levelKey}.from-name"] = ['nullable', 'string', 'max:255'];
            $rules["{$levelKey}.reply-to"] = new EmailRule(false);
            $rules["{$levelKey}.reply-to-name"] = ['nullable', 'string', 'max:255'];

            $fields["{$levelKey}.from-name"] = [
                'label' => 'From name',
                'type' => 'text',
                'placeholder' => 'Use '.kSiteConfig('name'),
                'description' => 'The name that appears in the "from" field of outgoing emails.',
            ];
            $fields["{$levelKey}.reply-to"] = [
                'label' => 'Reply-to address',
                'type' => 'email',
                'placeholder' => 'e.g. example@yourdomain.com',
                'description' => 'The email address that appears in the "reply-to" field of outgoing emails.',
            ];
            $fields["{$levelKey}.reply-to-name"] = [
                'label' => 'Reply-to name',
                'type' => 'text',
                'placeholder' => 'e.g. Your Company Name',
                'description' => 'The name that appears in the "reply-to" field of outgoing emails.',
            ];

            $result[$sender->value] = [
                'label' => $sender->label(),
                'key' => "config.emails.{$sender->value}",
                'fields' => $fields,
                'rules' => $rules,
            ];
        }

        return $result;
    }

    /**
     * A switch that is off takes its dependent fields off the screen with it, so
     * those fields are only validated while they are actually being shown —
     * otherwise the save fails on a field the administrator cannot see.
     */
    protected function rules(): array
    {
        return collect($this->senderFields)->pluck('rules')->collapse()->toArray();
    }

    public function save(): bool
    {
        $this->checkGate();

        $this->saveConfig();

        $this->redirectRoute('admin.config.email-senders', navigate: true);

        return $this->respondSuccess();
    }
};
?>

<form wire:submit="save" class="space-y-6">
    <flux:card class="space-y-6">
        <div class="">
            <flux:heading level="2" size="lg">Email Senders</flux:heading>
            <flux:text class="mt-1">
                Configure the "from" and "reply-to" addresses for outgoing emails.
                Each sender can have its own settings, allowing you to customize the email experience for different types of notifications.
            </flux:text>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            @foreach ($this->senderFields as $senderField)
                <flux:card class="space-y-4">
                    <div class="mb-4">
                        <flux:heading level="3" size="md">{{ $senderField['label'] }}</flux:heading>
                    </div>

                    @foreach ($senderField['fields'] as $fieldKey => $field)
                        <flux:input
                            wire:model="{{ $fieldKey }}"
                            :label="$field['label']"
                            :type="$field['type']"
                            :placeholder="$field['placeholder']"
                            :description="$field['description']"
                            :badge="$field['badge'] ?? null"
                        />
                    @endforeach
                </flux:card>
            @endforeach
        </div>
    </flux:card>

    <x-util.floating-actions position="end">
        <x-dashboard.gate.button :gate="$pageGate" :level="$gateModify" icon="check">
            Save senders
        </x-dashboard.gate.button>
    </x-util.floating-actions>
</form>

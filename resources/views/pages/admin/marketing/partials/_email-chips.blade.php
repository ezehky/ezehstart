{{--
    A list of email addresses entered as chips — the recipients step and the send-a-
    test dialog both use it.

        @include('pages.admin.marketing.partials._email-chips', [
            'emails' => $test_emails,
            'model'  => 'test_email_input',
            'add'    => 'addTestEmail',
            'remove' => 'removeTestEmail',
        ])

    Enter is not the only way to finish an address. A phone keyboard puts Enter
    somewhere awkward and often replaces it with "Go", so space and comma commit
    too, and leaving the field commits whatever is in it. A paste is handed over
    whole — the component splits it, semicolons and newlines included — so a column
    copied out of a spreadsheet lands as a list rather than as one very long
    invalid address.
--}}

<span
    class="flex flex-wrap items-center gap-1.5 rounded-lg border border-slate-200 p-2 focus-within:border-lime-400 dark:border-slate-700"
    x-on:click="$refs.entry.focus()"
>
    @foreach ($emails as $email)
        <flux:badge size="sm" wire:key="chip-{{ $model }}-{{ md5($email) }}">
            {{ $email }}
            <button type="button" wire:click="{{ $remove }}('{{ $email }}')" class="ms-1" aria-label="Remove {{ $email }}">&times;</button>
        </flux:badge>
    @endforeach

    <input
        x-ref="entry"
        type="text"
        inputmode="email"
        autocomplete="off"
        wire:model="{{ $model }}"
        wire:keydown.enter.prevent="{{ $add }}"
        wire:keydown.space.prevent="{{ $add }}"
        wire:keydown.comma.prevent="{{ $add }}"
        wire:blur="{{ $add }}"
        x-on:paste.prevent="
            $wire.set(@js($model), (event.clipboardData || window.clipboardData).getData('text'), false)
                .then(() => $wire.{{ $add }}())
        "
        placeholder="Type or paste addresses"
        class="min-w-40 flex-1 border-0 bg-transparent p-1 text-sm outline-none"
    >
</span>

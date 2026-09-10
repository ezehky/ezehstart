@props([
    'label' => null,
    'description' => null,
    'placeholder' => '',
    'name' => null,
])

{{--
    A tiptap editor bound to a Livewire property.

    Use it exactly like a Flux field:

        <x-form.rich-text wire:model="content" label="Body" name="content" />

    The `content` Alpine property is the wire:model entanglement, so the editor
    writes back on blur and whenever the form asks it to flush. It is deliberately
    not live on every keystroke: a response landing mid-word moves the cursor.
--}}

@php($wireModel = $attributes->wire('model')->value())

<flux:field>
    @if ($label)
        <flux:label>{{ $label }}</flux:label>
    @endif

    @if ($description)
        <flux:description>{{ $description }}</flux:description>
    @endif

    <div
        wire:ignore
        x-data="{ content: @entangle($wireModel), ...richText(@js($placeholder)) }"
        x-on:image-picked.window="insertImage($event.detail.url, $event.detail.alt)"
        class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
    >
        {{-- Toolbar --}}
        <div class="flex flex-wrap items-center gap-1 border-b border-slate-200 bg-slate-50 p-2 dark:border-slate-700 dark:bg-slate-800">
            <flux:button size="xs" type="button" icon="bold" x-bind:variant="active.bold ? 'primary' : 'ghost'" x-on:click="run('toggleBold')" />
            <flux:button size="xs" type="button" icon="italic" x-bind:variant="active.italic ? 'primary' : 'ghost'" x-on:click="run('toggleItalic')" />
            <flux:button size="xs" type="button" icon="strikethrough" x-bind:variant="active.strike ? 'primary' : 'ghost'" x-on:click="run('toggleStrike')" />

            <flux:separator vertical class="mx-1 h-5" />

            <flux:button size="xs" type="button" x-bind:variant="active.h2 ? 'primary' : 'ghost'" x-on:click="run('toggleHeading', { level: 2 })">H2</flux:button>
            <flux:button size="xs" type="button" x-bind:variant="active.h3 ? 'primary' : 'ghost'" x-on:click="run('toggleHeading', { level: 3 })">H3</flux:button>

            <flux:separator vertical class="mx-1 h-5" />

            <flux:button size="xs" type="button" icon="list-bullet" x-bind:variant="active.bulletList ? 'primary' : 'ghost'" x-on:click="run('toggleBulletList')" />
            <flux:button size="xs" type="button" icon="numbered-list" x-bind:variant="active.orderedList ? 'primary' : 'ghost'" x-on:click="run('toggleOrderedList')" />
            <flux:button size="xs" type="button" icon="chat-bubble-left-right" x-bind:variant="active.blockquote ? 'primary' : 'ghost'" x-on:click="run('toggleBlockquote')" />
            <flux:button size="xs" type="button" icon="code-bracket" x-bind:variant="active.codeBlock ? 'primary' : 'ghost'" x-on:click="run('toggleCodeBlock')" />

            <flux:separator vertical class="mx-1 h-5" />

            <flux:button size="xs" type="button" icon="link" x-bind:variant="active.link ? 'primary' : 'ghost'" x-on:click="toggleLink()" />

            {{-- Opens the library picker. The picker dispatches a URL back, so
                 the editor never talks to the upload endpoint itself. --}}
            <flux:button size="xs" type="button" icon="photo" variant="ghost" x-on:click="requestImage()" />

            <flux:button size="xs" type="button" icon="arrow-uturn-left" variant="ghost" x-on:click="run('undo')" />
            <flux:button size="xs" type="button" icon="arrow-uturn-right" variant="ghost" x-on:click="run('redo')" />
        </div>

        <div x-ref="editor"></div>
    </div>

    @if ($name)
        <flux:error name="{{ $name }}" />
    @endif
</flux:field>

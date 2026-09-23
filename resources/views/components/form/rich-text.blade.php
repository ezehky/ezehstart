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
    writes back on blur and whenever the form asks it to flush. A plain wire:model
    is deferred — nothing leaves until the next request.

    A preview that has to follow the typing (the email builder's canvas) binds
    `wire:model.live.debounce.500ms` instead: the modifier reaches the entanglement,
    and the debounce holds each push back until typing pauses. The wrapper stays
    wire:ignore'd either way, so a response never reaches back into the document
    and the cursor stays put.

        <x-form.rich-text wire:model.live.debounce.500ms="{{ $prefix }}.text" />
--}}

@php
    $wireModel = $attributes->wire('model');

    // "debounce" followed by "500ms" — the same modifier pair wire:model reads
    $modifiers = $wireModel->modifiers();
    $debounce = $modifiers->contains('debounce')
        ? (int) ($modifiers->first(fn ($modifier) => str_ends_with($modifier, 'ms')) ?? 150)
        : 0;
@endphp

<flux:field>
    @if ($label)
        <flux:label>{{ $label }}</flux:label>
    @endif

    @if ($description)
        <flux:description>{{ $description }}</flux:description>
    @endif

    <div
        wire:ignore
        x-data="{ content: @entangle($wireModel), ...richText(@js($placeholder), @js($debounce)) }"
        x-on:image-picked.window="insertImage($event.detail.url, $event.detail.alt)"
        x-on:video-picked.window="insertVideo($event.detail.url)"
        x-on:personalize-token.window="insertToken($event.detail.token)"
        class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
    >
        {{-- Toolbar. Every control carries a tooltip: the row is nearly all icons,
             and several of them — strikethrough against italic, the four alignment
             bars against each other — are only told apart by their name. --}}
        <div class="flex flex-wrap items-center gap-1 border-b border-slate-200 bg-slate-50 p-2 dark:border-slate-700 dark:bg-slate-800">
            <flux:button
                size="xs"
                type="button"
                icon="bold"
                x-bind:variant="active.bold ? 'primary' : 'ghost'"
                x-on:click="run('toggleBold')"
                tooltip="Bold"
            />

            <flux:button
                size="xs"
                type="button"
                icon="italic"
                x-bind:variant="active.italic ? 'primary' : 'ghost'"
                x-on:click="run('toggleItalic')"
                tooltip="Italic"
            />

            <flux:button
                size="xs"
                type="button"
                icon="strikethrough"
                x-bind:variant="active.strike ? 'primary' : 'ghost'"
                x-on:click="run('toggleStrike')"
                tooltip="Strikethrough"
            />

            <flux:button
                size="xs"
                type="button"
                icon="subscript"
                x-bind:variant="active.subscript ? 'primary' : 'ghost'"
                x-on:click="run('toggleSubscript')"
                tooltip="Subscript"
            />

            <flux:button
                size="xs"
                type="button"
                icon="superscript"
                x-bind:variant="active.superscript ? 'primary' : 'ghost'"
                x-on:click="run('toggleSuperscript')"
                tooltip="Superscript"
            />

            <flux:separator vertical class="mx-1 h-5" />

            {{-- Text and background colour. Each opens a preset grid plus the native
                 picker, the same choice <x-form.color-field> offers; that component
                 cannot be reused here because it binds to a Livewire property, and a
                 colour here belongs to the selection, not to the form. The strip
                 under the icon shows the colour at the caret. --}}
            @foreach ([
                'color' => ['icon' => 'baseline', 'label' => 'Text colour'],
                'backgroundColor' => ['icon' => 'highlighter', 'label' => 'Background colour'],
            ] as $kind => $control)
                <div
                    class="relative"
                    x-data="{ open: false }"
                    x-on:keydown.escape.stop="open = false"
                    x-on:click.outside="open = false"
                >
                    <div class="relative" x-ref="trigger">
                        <flux:button
                            size="xs"
                            type="button"
                            icon="{{ $control['icon'] }}"
                            x-bind:variant="open ? 'primary' : 'ghost'"
                            x-on:click="open = ! open"
                            tooltip="{{ $control['label'] }}"
                        />

                        <span
                            class="pointer-events-none absolute inset-x-1.5 bottom-0.5 h-0.5 rounded-full"
                            x-bind:style="active.{{ $kind }} ? `background-color: ${active.{{ $kind }}}` : ''"
                        ></span>
                    </div>

                    <div
                        x-cloak
                        x-show="open"
                        x-transition.opacity
                        x-anchor.bottom-start.offset.8="$refs.trigger"
                        class="z-40 w-56 max-w-[calc(100vw-2rem)] rounded-xl border border-slate-200 bg-white p-3 shadow-lg dark:border-white/10 dark:bg-slate-900"
                    >
                        <div class="grid grid-cols-8 gap-1.5">
                            @foreach ([
                                '#ef4444', '#f97316', '#f59e0b', '#eab308',
                                '#84cc16', '#22c55e', '#10b981', '#14b8a6',
                                '#06b6d4', '#3b82f6', '#6366f1', '#8b5cf6',
                                '#d946ef', '#ec4899', '#0f172a', '#ffffff',
                            ] as $preset)
                                <button
                                    type="button"
                                    class="size-5 rounded ring-1 ring-inset ring-black/10 transition hover:scale-110 dark:ring-white/20"
                                    x-bind:class="active.{{ $kind }} === @js($preset) && 'ring-2 ring-lime-500'"
                                    style="background-color: {{ $preset }}"
                                    x-on:click="setColor(@js($kind), @js($preset)); open = false"
                                    aria-label="{{ $preset }}"
                                ></button>
                            @endforeach
                        </div>

                        <div class="mt-3 flex items-center justify-between gap-2 border-t border-slate-100 pt-3 dark:border-white/10">
                            <label class="flex cursor-pointer items-center gap-1.5 text-xs font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">
                                <flux:icon name="eye-dropper" class="size-3.5" />
                                Custom
                                {{-- `change`, not `input`: input fires on every drag
                                     step, and each apply refocuses the editor, which
                                     would close the native picker mid-drag. --}}
                                <input
                                    type="color"
                                    class="sr-only"
                                    x-bind:value="active.{{ $kind }} && active.{{ $kind }}.startsWith('#') ? active.{{ $kind }} : '#000000'"
                                    x-on:change="setColor(@js($kind), $event.target.value); open = false"
                                />
                            </label>

                            <button
                                type="button"
                                class="text-xs font-medium text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white"
                                x-on:click="setColor(@js($kind), ''); open = false"
                            >
                                Clear
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach

            <flux:separator vertical class="mx-1 h-5" />

            <flux:button
                size="xs"
                type="button"
                icon="heading-2"
                x-bind:variant="active.h2 ? 'primary' : 'ghost'"
                x-on:click="run('toggleHeading', { level: 2 })"
                tooltip="Heading 2"
            />

            <flux:button
                size="xs"
                type="button"
                icon="heading-3"
                x-bind:variant="active.h3 ? 'primary' : 'ghost'"
                x-on:click="run('toggleHeading', { level: 3 })"
                tooltip="Heading 2"
            />

            <flux:separator vertical class="mx-1 h-5" />

            {{-- Alignment writes `style="text-align: …"` onto the block, so these
                 four behave as one group rather than as independent switches:
                 setting one replaces whatever the block carried before. --}}
            <flux:button size="xs" type="button" icon="bars-3-bottom-left" x-bind:variant="active.alignLeft ? 'primary' : 'ghost'" x-on:click="run('setTextAlign', 'left')" tooltip="Align left" />

            <flux:button size="xs" type="button" icon="bars-3" x-bind:variant="active.alignCenter ? 'primary' : 'ghost'" x-on:click="run('setTextAlign', 'center')" tooltip="Align centre" />

            <flux:button size="xs" type="button" icon="bars-3-bottom-right" x-bind:variant="active.alignRight ? 'primary' : 'ghost'" x-on:click="run('setTextAlign', 'right')" tooltip="Align right" />

            <flux:button size="xs" type="button" icon="bars-4" x-bind:variant="active.alignJustify ? 'primary' : 'ghost'" x-on:click="run('setTextAlign', 'justify')" tooltip="Justify" />

            <flux:separator vertical class="mx-1 h-5" />

            <flux:button size="xs" type="button" icon="list-bullet" x-bind:variant="active.bulletList ? 'primary' : 'ghost'" x-on:click="run('toggleBulletList')" tooltip="Bulleted list" />

            <flux:button size="xs" type="button" icon="numbered-list" x-bind:variant="active.orderedList ? 'primary' : 'ghost'" x-on:click="run('toggleOrderedList')" tooltip="Numbered list" />

            <flux:button size="xs" type="button" icon="chat-bubble-left-right" x-bind:variant="active.blockquote ? 'primary' : 'ghost'" x-on:click="run('toggleBlockquote')" tooltip="Quote" />

            <flux:button size="xs" type="button" icon="code-bracket" x-bind:variant="active.codeBlock ? 'primary' : 'ghost'" x-on:click="run('toggleCodeBlock')" tooltip="Code block" />

            <flux:separator vertical class="mx-1 h-5" />

            <flux:button size="xs" type="button" icon="link" x-bind:variant="active.link || linkOpen ? 'primary' : 'ghost'" x-on:click="openLink()" tooltip="Link" />

            {{-- Opens the library picker. The picker dispatches a URL back, so
                 the editor never talks to the upload endpoint itself. --}}
            <flux:button size="xs" type="button" icon="photo" variant="ghost" x-on:click="requestImage()" tooltip="Insert image" />

            {{-- The same, for the video library. The picker hands back a player URL
                 the server built, which is the only kind the sanitiser keeps. --}}
            <flux:button size="xs" type="button" icon="film" variant="ghost" x-on:click="requestVideo()" tooltip="Insert video" />

            {{-- Inserting is the only table control kept up here; the rest appear in
                 their own bar once the caret is inside a table. --}}
            <flux:button size="xs" type="button" icon="table-cells" x-bind:variant="active.table ? 'primary' : 'ghost'" x-on:click="run('insertTable', { rows: 3, cols: 3, withHeaderRow: true })" tooltip="Insert table" />

            <flux:separator vertical class="mx-1 h-5" />

            <flux:button size="xs" type="button" icon="arrow-uturn-left" variant="ghost" x-on:click="run('undo')" tooltip="Undo" />

            <flux:button size="xs" type="button" icon="arrow-uturn-right" variant="ghost" x-on:click="run('redo')" tooltip="Redo" />
        </div>

        {{-- The link bar. It lives in the toolbar rather than in a prompt() so the
             URL can be corrected, dismissed with Escape, and styled with the rest
             of the form. --}}
        <div
            x-cloak
            x-show="linkOpen"
            x-on:keydown.escape.stop="closeLink()"
            class="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-slate-50 p-2 dark:border-slate-700 dark:bg-slate-800"
        >
            <flux:input
                size="sm"
                type="text"
                x-ref="linkInput"
                x-model="linkUrl"
                placeholder="https://example.com"
                x-on:keydown.enter.prevent.stop="applyLink()"
                class="min-w-48 flex-1"
            />

            {{-- Read when Apply is pressed, so the target can be changed on a link
                 that already exists without the URL being retyped. It arrives
                 checked for a new link, and showing the saved target for one being
                 edited. --}}
            <flux:checkbox
                label="Open in new tab"
                x-model="linkBlank"
                x-on:keydown.enter.prevent.stop="applyLink()"
            />

            <flux:button size="xs" type="button" variant="primary" x-on:click="applyLink()">Apply</flux:button>

            <flux:button size="xs" type="button" variant="ghost" x-show="active.link" x-on:click="removeLink()">Remove</flux:button>

            <flux:button size="xs" type="button" variant="ghost" icon="x-mark" x-on:click="closeLink()" />
        </div>

        {{-- The table bar, shown only while the caret is inside a table. These row
             and column controls have no meaning outside one, and carrying eight of
             them in the main toolbar permanently would bury the ones that do. --}}
        <div
            x-cloak
            x-show="active.table"
            class="flex flex-wrap items-center gap-1 border-b border-slate-200 bg-slate-50 p-2 dark:border-slate-700 dark:bg-slate-800"
        >
            <flux:text size="sm" class="me-1">Row</flux:text>

            <flux:button size="xs" type="button" icon="plus" variant="ghost" x-on:click="run('addRowAfter')" tooltip="Add row below" />

            <flux:button size="xs" type="button" icon="minus" variant="ghost" x-on:click="run('deleteRow')" tooltip="Delete row" />

            <flux:separator vertical class="mx-1 h-5" />

            <flux:text size="sm" class="me-1">Column</flux:text>

            <flux:button size="xs" type="button" icon="plus" variant="ghost" x-on:click="run('addColumnAfter')" tooltip="Add column after" />

            <flux:button size="xs" type="button" icon="minus" variant="ghost" x-on:click="run('deleteColumn')" tooltip="Delete column" />

            <flux:separator vertical class="mx-1 h-5" />

            <flux:button size="xs" type="button" icon="view-columns" variant="ghost" x-on:click="run('toggleHeaderRow')" tooltip="Toggle header row" />

            <flux:button size="xs" type="button" icon="arrows-pointing-in" variant="ghost" x-on:click="run('mergeOrSplit')" tooltip="Merge or split cells" />

            <flux:button size="xs" type="button" icon="trash" variant="ghost" x-on:click="run('deleteTable')" tooltip="Delete table" />
        </div>

        <div x-ref="editor"></div>
    </div>

    @if ($name)
        <flux:error name="{{ $name }}" />
    @endif
</flux:field>

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

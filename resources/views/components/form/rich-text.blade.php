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
            <flux:tooltip content="Bold">
                <flux:button size="xs" type="button" icon="bold" x-bind:variant="active.bold ? 'primary' : 'ghost'" x-on:click="run('toggleBold')" />
            </flux:tooltip>

            <flux:tooltip content="Italic">
                <flux:button size="xs" type="button" icon="italic" x-bind:variant="active.italic ? 'primary' : 'ghost'" x-on:click="run('toggleItalic')" />
            </flux:tooltip>

            <flux:tooltip content="Strikethrough">
                <flux:button size="xs" type="button" icon="strikethrough" x-bind:variant="active.strike ? 'primary' : 'ghost'" x-on:click="run('toggleStrike')" />
            </flux:tooltip>

            {{-- Subscript and superscript are lettered rather than iconed: the icon
                 set here is Heroicons, which carries no glyph for either, and the
                 H2/H3 buttons below already establish a lettered button as a mark. --}}
            <flux:tooltip content="Subscript">
                <flux:button size="xs" type="button" x-bind:variant="active.subscript ? 'primary' : 'ghost'" x-on:click="run('toggleSubscript')">X<sub class="text-[0.65em]">2</sub></flux:button>
            </flux:tooltip>

            <flux:tooltip content="Superscript">
                <flux:button size="xs" type="button" x-bind:variant="active.superscript ? 'primary' : 'ghost'" x-on:click="run('toggleSuperscript')">X<sup class="text-[0.65em]">2</sup></flux:button>
            </flux:tooltip>

            <flux:separator vertical class="mx-1 h-5" />

            <flux:tooltip content="Heading 2">
                <flux:button size="xs" type="button" x-bind:variant="active.h2 ? 'primary' : 'ghost'" x-on:click="run('toggleHeading', { level: 2 })">H2</flux:button>
            </flux:tooltip>

            <flux:tooltip content="Heading 3">
                <flux:button size="xs" type="button" x-bind:variant="active.h3 ? 'primary' : 'ghost'" x-on:click="run('toggleHeading', { level: 3 })">H3</flux:button>
            </flux:tooltip>

            <flux:separator vertical class="mx-1 h-5" />

            {{-- Alignment writes `style="text-align: …"` onto the block, so these
                 four behave as one group rather than as independent switches:
                 setting one replaces whatever the block carried before. --}}
            <flux:tooltip content="Align left">
                <flux:button size="xs" type="button" icon="bars-3-bottom-left" x-bind:variant="active.alignLeft ? 'primary' : 'ghost'" x-on:click="run('setTextAlign', 'left')" />
            </flux:tooltip>

            <flux:tooltip content="Align centre">
                <flux:button size="xs" type="button" icon="bars-3" x-bind:variant="active.alignCenter ? 'primary' : 'ghost'" x-on:click="run('setTextAlign', 'center')" />
            </flux:tooltip>

            <flux:tooltip content="Align right">
                <flux:button size="xs" type="button" icon="bars-3-bottom-right" x-bind:variant="active.alignRight ? 'primary' : 'ghost'" x-on:click="run('setTextAlign', 'right')" />
            </flux:tooltip>

            <flux:tooltip content="Justify">
                <flux:button size="xs" type="button" icon="bars-4" x-bind:variant="active.alignJustify ? 'primary' : 'ghost'" x-on:click="run('setTextAlign', 'justify')" />
            </flux:tooltip>

            <flux:separator vertical class="mx-1 h-5" />

            <flux:tooltip content="Bulleted list">
                <flux:button size="xs" type="button" icon="list-bullet" x-bind:variant="active.bulletList ? 'primary' : 'ghost'" x-on:click="run('toggleBulletList')" />
            </flux:tooltip>

            <flux:tooltip content="Numbered list">
                <flux:button size="xs" type="button" icon="numbered-list" x-bind:variant="active.orderedList ? 'primary' : 'ghost'" x-on:click="run('toggleOrderedList')" />
            </flux:tooltip>

            <flux:tooltip content="Quote">
                <flux:button size="xs" type="button" icon="chat-bubble-left-right" x-bind:variant="active.blockquote ? 'primary' : 'ghost'" x-on:click="run('toggleBlockquote')" />
            </flux:tooltip>

            <flux:tooltip content="Code block">
                <flux:button size="xs" type="button" icon="code-bracket" x-bind:variant="active.codeBlock ? 'primary' : 'ghost'" x-on:click="run('toggleCodeBlock')" />
            </flux:tooltip>

            <flux:separator vertical class="mx-1 h-5" />

            <flux:tooltip content="Link">
                <flux:button size="xs" type="button" icon="link" x-bind:variant="active.link || linkOpen ? 'primary' : 'ghost'" x-on:click="openLink()" />
            </flux:tooltip>

            {{-- Opens the library picker. The picker dispatches a URL back, so
                 the editor never talks to the upload endpoint itself. --}}
            <flux:tooltip content="Insert image">
                <flux:button size="xs" type="button" icon="photo" variant="ghost" x-on:click="requestImage()" />
            </flux:tooltip>

            {{-- The same, for the video library. The picker hands back a player URL
                 the server built, which is the only kind the sanitiser keeps. --}}
            <flux:tooltip content="Insert video">
                <flux:button size="xs" type="button" icon="film" variant="ghost" x-on:click="requestVideo()" />
            </flux:tooltip>

            {{-- Inserting is the only table control kept up here; the rest appear in
                 their own bar once the caret is inside a table. --}}
            <flux:tooltip content="Insert table">
                <flux:button size="xs" type="button" icon="table-cells" x-bind:variant="active.table ? 'primary' : 'ghost'" x-on:click="run('insertTable', { rows: 3, cols: 3, withHeaderRow: true })" />
            </flux:tooltip>

            <flux:separator vertical class="mx-1 h-5" />

            <flux:tooltip content="Undo">
                <flux:button size="xs" type="button" icon="arrow-uturn-left" variant="ghost" x-on:click="run('undo')" />
            </flux:tooltip>

            <flux:tooltip content="Redo">
                <flux:button size="xs" type="button" icon="arrow-uturn-right" variant="ghost" x-on:click="run('redo')" />
            </flux:tooltip>
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

            <flux:tooltip content="Add row below">
                <flux:button size="xs" type="button" icon="plus" variant="ghost" x-on:click="run('addRowAfter')" />
            </flux:tooltip>

            <flux:tooltip content="Delete row">
                <flux:button size="xs" type="button" icon="minus" variant="ghost" x-on:click="run('deleteRow')" />
            </flux:tooltip>

            <flux:separator vertical class="mx-1 h-5" />

            <flux:text size="sm" class="me-1">Column</flux:text>

            <flux:tooltip content="Add column after">
                <flux:button size="xs" type="button" icon="plus" variant="ghost" x-on:click="run('addColumnAfter')" />
            </flux:tooltip>

            <flux:tooltip content="Delete column">
                <flux:button size="xs" type="button" icon="minus" variant="ghost" x-on:click="run('deleteColumn')" />
            </flux:tooltip>

            <flux:separator vertical class="mx-1 h-5" />

            <flux:tooltip content="Toggle header row">
                <flux:button size="xs" type="button" icon="view-columns" variant="ghost" x-on:click="run('toggleHeaderRow')" />
            </flux:tooltip>

            <flux:tooltip content="Merge or split cells">
                <flux:button size="xs" type="button" icon="arrows-pointing-in" variant="ghost" x-on:click="run('mergeOrSplit')" />
            </flux:tooltip>

            <flux:tooltip content="Delete table">
                <flux:button size="xs" type="button" icon="trash" variant="ghost" x-on:click="run('deleteTable')" />
            </flux:tooltip>
        </div>

        <div x-ref="editor"></div>
    </div>

    @if ($name)
        <flux:error name="{{ $name }}" />
    @endif
</flux:field>

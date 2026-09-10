import { Editor } from "@tiptap/core";
import StarterKit from "@tiptap/starter-kit";
import Image from "@tiptap/extension-image";
import Placeholder from "@tiptap/extension-placeholder";
import TextAlign from "@tiptap/extension-text-align";

/**
 * The tiptap editor behind <x-form.rich-text>.
 *
 * Registered as an Alpine component rather than initialised per page, so a
 * Livewire re-render does not leave a second editor attached to the same element.
 *
 * The editor owns the HTML while somebody is typing; the wire:model property is
 * updated on blur and on submit rather than on every keystroke. Round-tripping a
 * document through the server on each character is what makes rich-text editors
 * feel broken — the cursor jumps whenever a response lands mid-word.
 */
export default (placeholder = "") => ({
    editor: null,

    /** Mirrors the editor's state so the toolbar can show what is active. */
    active: {},

    /**
     * Set while this editor is the one waiting on the image picker, so a page
     * holding two editors does not put the chosen image into both.
     */
    awaitingImage: false,

    init() {
        this.editor = new Editor({
            element: this.$refs.editor,
            extensions: [
                StarterKit.configure({
                    heading: { levels: [2, 3, 4] },
                }),
                Image.configure({ inline: false, allowBase64: false }),
                TextAlign.configure({ types: ["heading", "paragraph"] }),
                Placeholder.configure({ placeholder }),
            ],
            // `content` is the entangled Livewire property, so it already holds
            // whatever the server sent — no second copy has to be passed in.
            content: this.content || "",
            editorProps: {
                attributes: {
                    class: "prose prose-slate dark:prose-invert max-w-none min-h-[16rem] p-4 focus:outline-none",
                },
            },
            onTransaction: () => this.refreshActive(),
            onBlur: () => this.push(),
        });

        this.refreshActive();

        // The form asks for the current HTML before it submits, because a click
        // straight from the editor onto the save button can beat the blur.
        this.$el.addEventListener("rich-text:flush", () => this.push());
    },

    /**
     * Livewire can replace the surrounding DOM; without this the editor's
     * ProseMirror instance leaks and keeps listening to a detached node. Alpine
     * calls destroy() on a data component when its element goes away.
     */
    destroy() {
        this.editor?.destroy();
    },

    /** Write the editor's HTML back to the Livewire property. */
    push() {
        const html = this.editor.isEmpty ? "" : this.editor.getHTML();

        if (html !== this.content) {
            this.content = html;
        }
    },

    refreshActive() {
        const e = this.editor;

        this.active = {
            bold: e.isActive("bold"),
            italic: e.isActive("italic"),
            strike: e.isActive("strike"),
            bulletList: e.isActive("bulletList"),
            orderedList: e.isActive("orderedList"),
            blockquote: e.isActive("blockquote"),
            codeBlock: e.isActive("codeBlock"),
            h2: e.isActive("heading", { level: 2 }),
            h3: e.isActive("heading", { level: 3 }),
            link: e.isActive("link"),
        };
    },

    run(command, ...args) {
        this.editor.chain().focus()[command](...args).run();
    },

    toggleLink() {
        if (this.editor.isActive("link")) {
            this.run("unsetLink");
            return;
        }

        const url = window.prompt("Link URL");

        if (!url) return;

        // Refuse the schemes that turn a link into script execution. The server
        // sanitises too, but a link that never gets typed is one fewer to strip.
        if (/^\s*(javascript|vbscript|data):/i.test(url)) return;

        this.editor
            .chain()
            .focus()
            .extendMarkRange("link")
            .setLink({ href: url, target: "_blank", rel: "noopener nofollow" })
            .run();
    },

    /**
     * Ask the picker for an image. The editor never talks to the upload endpoint
     * itself — it only ever receives a URL back.
     */
    requestImage() {
        this.awaitingImage = true;

        this.$dispatch("open-image-picker");
    },

    /**
     * Called when the picker announces a choice. Only the editor that asked for
     * one takes it.
     */
    insertImage(url, alt = "") {
        if (!this.awaitingImage || !url) return;

        this.awaitingImage = false;

        this.editor.chain().focus().setImage({ src: url, alt }).run();
        this.push();
    },
});

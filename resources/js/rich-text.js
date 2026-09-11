import { Editor } from "@tiptap/core";
import StarterKit from "@tiptap/starter-kit";
import ResizableImage from "./resizable-image";
import Placeholder from "@tiptap/extension-placeholder";
import TextAlign from "@tiptap/extension-text-align";
import Subscript from "@tiptap/extension-subscript";
import Superscript from "@tiptap/extension-superscript";
import { TableKit } from "@tiptap/extension-table";
import VideoEmbed from "./video-embed";

/**
 * The tiptap editor behind <x-form.rich-text>.
 *
 * Registered as an Alpine component rather than initialised per page, so a
 * Livewire re-render does not leave a second editor attached to the same element.
 *
 * The editor writes its HTML back to the wire:model property on every change, but
 * the entanglement is deferred and the wrapper is wire:ignore, so no request goes
 * out per keystroke and no server response can reach back into the document. That
 * is what keeps the cursor still — round-tripping a document through the server
 * mid-word is what makes rich-text editors feel broken — while still guaranteeing
 * the property is current when a click goes straight from the editor to Save.
 */
export default (placeholder = "") => {
    /**
     * The editor is held here, in the factory's closure, and deliberately NOT as a
     * property on the returned object.
     *
     * Alpine passes x-data through Vue's reactive(), which deep-proxies anything
     * whose raw type is "Object" — a class instance included. A proxied tiptap
     * editor still answers isActive() and still applies transactions, so the
     * toolbar lights up and nothing else happens: ProseMirror tracks its nodes and
     * decorations by object identity, and once the view is comparing proxies
     * against the raw nodes its plugins hold, it stops repainting. Bold "works"
     * and the text never goes bold. Keeping the instance out of the reactive tree
     * is the whole fix, and it also spares Alpine from proxying the document on
     * every keystroke.
     */
    let editor = null;

    return {
        /** Mirrors the editor's state so the toolbar can show what is active. */
        active: {},

        /**
         * Set while this editor is the one waiting on the image picker, so a page
         * holding two editors does not put the chosen image into both.
         */
        awaitingImage: false,

        /**
         * The same, for the video picker. Two flags rather than one: a page can
         * hold two editors, and either could be waiting on either picker.
         */
        awaitingVideo: false,

        /** The inline link bar: open while it is being filled in, plus its value. */
        linkOpen: false,
        linkUrl: "",

        /**
         * Whether the link being written should open in a new tab. Held here
         * rather than derived from the selection because the checkbox has to stay
         * answerable while the bar is open and the selection has not changed yet.
         */
        linkBlank: true,

        init() {
            editor = new Editor({
                element: this.$refs.editor,
                extensions: [
                    StarterKit.configure({
                        heading: { levels: [2, 3, 4] },
                        // Link's own defaults would stamp target="_blank" onto
                        // every anchor, which would make the new-tab checkbox a
                        // control that can only ever be turned on. Nulling them
                        // here leaves both attributes to applyLink().
                        link: { HTMLAttributes: { target: null, rel: null } },
                    }),
                    ResizableImage.configure({
                        inline: false,
                        allowBase64: false,
                    }),
                    VideoEmbed,
                    TextAlign.configure({ types: ["heading", "paragraph"] }),
                    Subscript,
                    Superscript,
                    // Resizable columns: a table of prose is unreadable at the
                    // equal widths it is created with, and the drag is the only
                    // width control the toolbar does not have to carry.
                    TableKit.configure({ table: { resizable: true } }),
                    Placeholder.configure({ placeholder }),
                ],
                // `content` is the entangled Livewire property, so it already holds
                // whatever the server sent — no second copy has to be passed in.
                content: this.content || "",
                editorProps: {
                    attributes: {
                        class: "rich-prose max-w-none min-h-[16rem] p-4 focus:outline-none",
                    },
                },
                onTransaction: () => this.refreshActive(),
                onUpdate: () => this.push(),
                onBlur: () => this.push(),
            });

            this.refreshActive();
        },

        /**
         * Livewire can replace the surrounding DOM; without this the editor's
         * ProseMirror instance leaks and keeps listening to a detached node. Alpine
         * calls destroy() on a data component when its element goes away.
         */
        destroy() {
            editor?.destroy();
            editor = null;
        },

        /** Write the editor's HTML back to the Livewire property. */
        push() {
            if (!editor) return;

            const html = editor.isEmpty ? "" : editor.getHTML();

            if (html !== this.content) {
                this.content = html;
            }
        },

        refreshActive() {
            if (!editor) return;

            this.active = {
                bold: editor.isActive("bold"),
                italic: editor.isActive("italic"),
                strike: editor.isActive("strike"),
                bulletList: editor.isActive("bulletList"),
                orderedList: editor.isActive("orderedList"),
                blockquote: editor.isActive("blockquote"),
                codeBlock: editor.isActive("codeBlock"),
                subscript: editor.isActive("subscript"),
                superscript: editor.isActive("superscript"),
                h2: editor.isActive("heading", { level: 2 }),
                h3: editor.isActive("heading", { level: 3 }),
                link: editor.isActive("link"),

                // Alignment reads off the attribute rather than a node name, so
                // a left-aligned heading and a left-aligned paragraph both light
                // the same button.
                alignLeft: editor.isActive({ textAlign: "left" }),
                alignCenter: editor.isActive({ textAlign: "center" }),
                alignRight: editor.isActive({ textAlign: "right" }),
                alignJustify: editor.isActive({ textAlign: "justify" }),

                // Drives the table bar, which is shown only while the caret is
                // inside a table — nine row and column controls have no meaning
                // anywhere else and would only crowd the toolbar.
                table: editor.isActive("table"),
            };
        },

        run(command, ...args) {
            editor
                .chain()
                .focus()
                [command](...args)
                .run();

            this.refreshActive();
        },

        /**
         * Opens the inline link bar, seeded with the href already on the selection
         * so an existing link is edited rather than retyped. The toolbar owns the
         * input — a native prompt() steals focus, cannot be styled, and cannot be
         * dismissed with Escape.
         */
        openLink() {
            const attributes = editor.getAttributes("link");

            this.linkUrl = attributes.href || "";

            // An existing link reports what it was actually saved with; a new one
            // starts checked, which is how every link this editor wrote before the
            // checkbox existed behaved.
            this.linkBlank = attributes.href
                ? attributes.target === "_blank"
                : true;

            this.linkOpen = true;

            this.$nextTick(() => this.$refs.linkInput?.focus());
        },

        closeLink() {
            this.linkOpen = false;
            this.linkUrl = "";

            editor.chain().focus().run();
        },

        /** Apply what is in the link bar. An empty value clears the link instead. */
        applyLink() {
            const url = this.linkUrl.trim();

            if (!url) {
                this.removeLink();
                return;
            }

            // Refuse the schemes that turn a link into script execution. The server
            // sanitises too, but a link that never gets typed is one fewer to strip.
            if (/^\s*(javascript|vbscript|data):/i.test(url)) {
                this.closeLink();
                return;
            }

            // A bare domain is what people actually type; without a scheme the
            // browser resolves it against the current path and the link goes nowhere.
            const href = /^(https?:|mailto:|tel:|#|\/)/i.test(url)
                ? url
                : `https://${url}`;

            // noopener only matters on a link that hands over a window, so it is
            // set with the target rather than always: a same-tab link carrying it
            // is noise in the stored HTML.
            editor
                .chain()
                .focus()
                .extendMarkRange("link")
                .setLink({
                    href,
                    target: this.linkBlank ? "_blank" : null,
                    rel: this.linkBlank ? "noopener nofollow" : "nofollow",
                })
                .run();

            this.linkOpen = false;
            this.linkUrl = "";
            this.refreshActive();
            this.push();
        },

        removeLink() {
            editor.chain().focus().extendMarkRange("link").unsetLink().run();

            this.linkOpen = false;
            this.linkUrl = "";
            this.refreshActive();
            this.push();
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

            editor.chain().focus().setImage({ src: url, alt }).run();
            this.push();
        },

        /**
         * Ask the video picker for an embed. As with images, the editor never
         * resolves a URL itself — it receives a player URL the server built from
         * the provider and id on a library row, which is the only kind of src the
         * sanitiser will keep.
         */
        requestVideo() {
            this.awaitingVideo = true;

            this.$dispatch("open-video-picker");
        },

        /**
         * Called when the video picker announces a choice. Only the editor that
         * asked for one takes it.
         */
        insertVideo(url) {
            if (!this.awaitingVideo || !url) return;

            this.awaitingVideo = false;

            editor.chain().focus().setVideoEmbed(url).run();
            this.push();
        },
    };
};

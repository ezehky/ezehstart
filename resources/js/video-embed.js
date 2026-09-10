import { Node, mergeAttributes } from "@tiptap/core";

/**
 * A video embed in the tiptap document, stored as the bare <iframe> a reader gets.
 *
 * The node holds only a src, and the src it holds is always a player URL the
 * server produced — the picker hands one over, and BlogService::sanitize() throws
 * away every iframe on the way in and writes it again from the provider and id it
 * could resolve. Nothing typed here reaches a reader unexamined, which is why the
 * editor is allowed to put an iframe in the document at all.
 *
 * An atom with no node view: ProseMirror treats the whole thing as one unit, so it
 * selects and deletes as a block. `.ProseMirror iframe` is pointer-events: none in
 * the stylesheet, or the frame would swallow the click that selects it and the
 * only way to remove a video would be to select around it.
 */
export default Node.create({
    name: "videoEmbed",

    group: "block",

    atom: true,

    draggable: true,

    selectable: true,

    addAttributes() {
        return {
            src: { default: null },
        };
    },

    parseHTML() {
        return [{ tag: "iframe[src]" }];
    },

    renderHTML({ HTMLAttributes }) {
        // The permission attributes are written again on the server, so these are
        // for the editor's own preview rather than for the saved document.
        return [
            "iframe",
            mergeAttributes(HTMLAttributes, {
                loading: "lazy",
                allowfullscreen: "true",
                referrerpolicy: "strict-origin-when-cross-origin",
            }),
        ];
    },

    addCommands() {
        return {
            setVideoEmbed:
                (src) =>
                ({ commands }) => {
                    if (!src) return false;

                    return commands.insertContent({
                        type: this.name,
                        attrs: { src },
                    });
                },
        };
    },
});

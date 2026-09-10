import Image from "@tiptap/extension-image";

/**
 * The smallest an image may be dragged to. Below this the handles overlap each
 * other and the picture stops being something a reader can make out anyway.
 */
const MIN_WIDTH = 80;

/** The corners that carry a handle, and which way each one grows the image. */
const CORNERS = {
    nw: -1,
    ne: 1,
    sw: -1,
    se: 1,
};

/**
 * The image node, with corner handles.
 *
 * tiptap's Image is extended rather than replaced: parsing, the `setImage`
 * command and the inline/allowBase64 options all still come from upstream, and
 * this adds one attribute and the node view that lets somebody drag it.
 *
 * The width is stored as a plain integer in the HTML `width` attribute — not as
 * inline `style` — for two reasons. `BlogService::sanitize()` keeps the attributes
 * on a tag it allows, so a free-form `style` would be a stylesheet an author could
 * write onto a reader's page, while a numeric width is something the server can
 * clamp and does. And Tailwind's preflight puts `max-width: 100%` on every image,
 * so a width dragged wider than a phone still shrinks to fit rather than pushing
 * the article sideways.
 *
 * The node view exists only inside the editor. renderHTML still emits a bare
 * <img>, so what gets saved is the same tag it always was with one more attribute
 * on it — the same arrangement videoEmbed uses, and for the same reason.
 */
export default Image.extend({
    addAttributes() {
        return {
            ...this.parent?.(),

            width: {
                default: null,
                parseHTML: (element) => {
                    const width = Number.parseInt(
                        element.getAttribute("width") || "",
                        10,
                    );

                    return Number.isFinite(width) && width > 0 ? width : null;
                },
                renderHTML: (attributes) =>
                    attributes.width ? { width: attributes.width } : {},
            },
        };
    },

    addNodeView() {
        return ({ node, editor, getPos }) => {
            // The wrapper hugs the picture (w-fit in the stylesheet) so the handles
            // sit on the image's corners rather than the column's.
            const dom = document.createElement("div");
            dom.classList.add("rich-image");

            const img = document.createElement("img");
            dom.append(img);

            const render = (attrs) => {
                img.setAttribute("src", attrs.src ?? "");

                attrs.alt
                    ? img.setAttribute("alt", attrs.alt)
                    : img.removeAttribute("alt");

                attrs.title
                    ? img.setAttribute("title", attrs.title)
                    : img.removeAttribute("title");

                img.style.width = attrs.width ? `${attrs.width}px` : "";
            };

            /**
             * Commit a width to the document.
             *
             * Attributes are read back out of the current state rather than off the
             * `node` this node view closed over: that reference goes stale the first
             * time anything else edits the image, and writing it back would undo
             * whatever that was.
             */
            const commit = (width) => {
                if (typeof getPos !== "function") return;

                const pos = getPos();
                const current = editor.view.state.doc.nodeAt(pos);

                if (!current) return;

                editor.view.dispatch(
                    editor.view.state.tr.setNodeMarkup(pos, undefined, {
                        ...current.attrs,
                        width,
                    }),
                );
            };

            const startResize = (event, direction) => {
                // Without this the pointerdown reaches ProseMirror, which starts a
                // node drag — the image would be picked up instead of resized.
                event.preventDefault();
                event.stopPropagation();

                const startX = event.clientX;
                const startWidth = img.getBoundingClientRect().width;

                // The editor's own column is the ceiling. An image wider than the
                // text it sits in is one the reader scrolls sideways to see.
                const maxWidth = editor.view.dom.clientWidth;

                let width = Math.round(startWidth);

                const onMove = (moveEvent) => {
                    const dragged =
                        startWidth + (moveEvent.clientX - startX) * direction;

                    width = Math.max(
                        MIN_WIDTH,
                        Math.min(Math.round(dragged), maxWidth),
                    );

                    // Painted straight onto the element while the pointer is down.
                    // Dispatching per pixel would put a transaction — and an undo
                    // step — into the history for every mouse move.
                    img.style.width = `${width}px`;
                };

                const onUp = () => {
                    window.removeEventListener("pointermove", onMove);
                    window.removeEventListener("pointerup", onUp);

                    commit(width);
                };

                window.addEventListener("pointermove", onMove);
                window.addEventListener("pointerup", onUp);
            };

            Object.entries(CORNERS).forEach(([corner, direction]) => {
                const handle = document.createElement("span");

                handle.className = `rich-image-handle rich-image-handle-${corner}`;
                handle.addEventListener("pointerdown", (event) =>
                    startResize(event, direction),
                );

                dom.append(handle);
            });

            render(node.attrs);

            return {
                dom,

                update(updatedNode) {
                    if (updatedNode.type.name !== node.type.name) return false;

                    render(updatedNode.attrs);

                    return true;
                },

                // The handles are ours, and ProseMirror must not read a pointerdown
                // on one as the start of a selection or a drag.
                stopEvent: (event) =>
                    event.target instanceof HTMLElement &&
                    event.target.classList.contains("rich-image-handle"),

                // There is no contentDOM, so every mutation inside this node view is
                // one render() made. Letting ProseMirror re-read the DOM here would
                // have it parse the handles back as document content.
                ignoreMutation: () => true,
            };
        };
    },
});

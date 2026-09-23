import { Editor } from "@tiptap/core";
import StarterKit from "@tiptap/starter-kit";
import ResizableImage from "./resizable-image";
import Placeholder from "@tiptap/extension-placeholder";
import TextAlign from "@tiptap/extension-text-align";
import Subscript from "@tiptap/extension-subscript";
import Superscript from "@tiptap/extension-superscript";
import { TableKit } from "@tiptap/extension-table";
import { TextStyle, Color, BackgroundColor } from "@tiptap/extension-text-style";
import VideoEmbed from "./video-embed";

/**
 * Everything tiptap, kept out of rich-text.js so it can be imported lazily.
 *
 * ProseMirror and the extensions are most of the bundle's weight, and only the
 * screens holding a <x-form.rich-text> need them. rich-text.js imports this module
 * from init(), so Vite splits it into its own chunk and every other page loads
 * without it.
 */
export function createEditor({ element, placeholder, content, ...options }) {
    return new Editor({
        element,
        extensions: [
            StarterKit.configure({
                heading: { levels: [2, 3, 4] },
                // Link's own defaults would stamp target="_blank" onto every
                // anchor, which would make the new-tab checkbox a control that can
                // only ever be turned on. Nulling them here leaves both attributes
                // to applyLink().
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
            // Resizable columns: a table of prose is unreadable at the equal
            // widths it is created with, and the drag is the only width control
            // the toolbar does not have to carry.
            TableKit.configure({ table: { resizable: true } }),
            // Both write onto one <span style="…"> through the textStyle mark, so
            // a run that has a text colour and a background colour is still a
            // single span.
            TextStyle,
            Color,
            BackgroundColor,
            Placeholder.configure({ placeholder }),
        ],
        content,
        ...options,
    });
}

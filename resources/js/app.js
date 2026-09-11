import {
    Livewire,
    Alpine,
} from "../../vendor/livewire/livewire/dist/livewire.esm";
import richText from "./rich-text";
import chart from "./chart";
import countdownTimer from "./countdown-timer";
import floatingActions from "./floating-actions";
import datePicker from "./date-picker";
// ||||||||||||||||||||||||||
// ALPINE
// PLUGINS

// COMPONENTS

/**
 * The tiptap editor behind <x-form.rich-text>. Registered here so a Livewire
 * re-render reuses the registration instead of booting a second editor onto the
 * same element.
 */
Alpine.data("richText", richText);

/**
 * The SVG chart engine behind <x-chart> and its sub-components. Registered once
 * here so the geometry is recomputed on a Livewire re-render rather than the
 * component being booted a second time onto the same <svg>.
 */
Alpine.data("chart", chart);

/**
 * The clock behind <x-util.countdown>. Registered once here so the numbers pick
 * up where they belong after a Livewire re-render rather than a second timer
 * being started on the same element.
 */
Alpine.data("countdownTimer", countdownTimer);

/**
 * The docking action bar behind <x-util.floating-actions>. Registered once here so
 * a Livewire re-render rebinds the observers to the element still on screen rather
 * than leaving the old pair watching a node that has been patched away.
 */
Alpine.data("floatingActions", floatingActions);

/**
 * The calendar behind <x-form.date-field>. Registered once here so a Livewire
 * re-render reopens the month the field is already showing rather than booting a
 * second calendar onto the same input.
 */
Alpine.data("datePicker", datePicker);

/**
 * Adds a class the first time an element scrolls into view, so entrance
 * animations are declared in the markup rather than wired up per page.
 *
 * Bound on <body> in components/layouts/base.blade.php, then used as:
 *   <div x-bind="animate('animate-fade-in')">
 */
Alpine.data("animationOnScroll", () => ({
    animate($class) {
        return {
            ["x-intersect"]() {
                return this.$el.classList.add($class);
            },
        };
    },
}));

Livewire.start();

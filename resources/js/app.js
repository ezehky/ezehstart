import {
    Livewire,
    Alpine,
} from "../../vendor/livewire/livewire/dist/livewire.esm";
import richText from "./rich-text";
import chart from "./chart";
import countdownTimer from "./countdown-timer";
import counter from "./counter";
import datePicker from "./date-picker";
import turnstileWidget from "./turnstile";
import passkey from "./passkey";
import phoneInput from "./phone-input";
import moneyInput from "./money-input";
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
 * The count-up behind <x-util.counter>. Registered once here so a Livewire
 * re-render keeps the number where it is rather than starting a second count on
 * the same element.
 */
Alpine.data("counter", counter);

/**
 * The calendar behind <x-form.date-field>. Registered once here so a Livewire
 * re-render reopens the month the field is already showing rather than booting a
 * second calendar onto the same input.
 */
Alpine.data("datePicker", datePicker);

/**
 * The Cloudflare Turnstile widget behind <x-form.captcha>. Registered once here so
 * a Livewire re-render rebinds to the widget already on screen rather than booting
 * a second challenge onto the same element.
 */
Alpine.data("turnstileWidget", turnstileWidget);

/**
 * The WebAuthn prompt behind the passkey panel and the passkey sign-in button.
 * Registered once here so a Livewire re-render does not start a second ceremony
 * against a prompt that is already open.
 */
Alpine.data("passkey", passkey);

/**
 * The country picker behind <x-form.phone-field>. Registered once here so a
 * Livewire re-render keeps the intl-tel-input instance already on the field
 * rather than wrapping the input in a second dropdown.
 */
Alpine.data("phoneInput", phoneInput);

/**
 * The formatted amount behind <x-form.money-field>. Registered once here so a
 * Livewire re-render keeps the typed text rather than reformatting it under the
 * caret.
 */
Alpine.data("moneyInput", moneyInput);

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

import {
    Livewire,
    Alpine,
} from "../../vendor/livewire/livewire/dist/livewire.esm";
// ||||||||||||||||||||||||||
// ALPINE
// PLUGINS

// COMPONENTS

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

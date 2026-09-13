/**
 * The Cloudflare Turnstile widget behind <x-form.captcha>.
 *
 * The api.js script is injected on demand rather than sitting in the base layout,
 * because the login screen only asks for a captcha once an address has failed a
 * couple of sign-ins — by then the page has already been rendered, and a @push
 * from a Livewire re-render would never reach the stack.
 */
const SCRIPT_SRC =
    "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";

let loading = null;

/**
 * Resolve with the global Turnstile object, loading the script the first time and
 * handing every later caller the same promise. Explicit rendering is what lets a
 * widget be created against an element Livewire has just patched in.
 */
function loadTurnstile() {
    if (window.turnstile) return Promise.resolve(window.turnstile);

    loading ||= new Promise((resolve, reject) => {
        const script = document.createElement("script");

        script.src = SCRIPT_SRC;
        script.async = true;
        script.defer = true;
        script.onload = () => resolve(window.turnstile);
        script.onerror = () => {
            // Cleared so a visitor who comes back from a dropped connection gets
            // another attempt instead of a permanently poisoned promise.
            loading = null;
            reject(new Error("Turnstile could not be loaded."));
        };

        document.head.appendChild(script);
    });

    return loading;
}

export default (config = {}) => ({
    widgetId: null,

    async init() {
        const turnstile = await loadTurnstile().catch(() => null);

        // Nothing to render against if Cloudflare is unreachable. The server still
        // refuses the submit, so this fails closed rather than silently open.
        if (!turnstile || !config.sitekey) return;

        this.widgetId = turnstile.render(this.$refs.widget, {
            sitekey: config.sitekey,
            action: config.action || undefined,
            theme: document.documentElement.classList.contains("dark")
                ? "dark"
                : "light",
            // Deferred rather than live: the token is only of use to the form
            // submit, and a round trip per challenge would re-render the page
            // under the widget for nothing.
            callback: (token) => this.$wire.set(config.model, token, false),
            "expired-callback": () => this.$wire.set(config.model, null, false),
            "error-callback": () => this.$wire.set(config.model, null, false),
        });
    },

    /**
     * Issue a fresh token. A Turnstile token is accepted once, so every failed
     * submit has to come back with a new one — WithCaptcha::resetCaptcha() is what
     * dispatches the event that lands here.
     */
    reset() {
        if (this.widgetId === null) return;

        window.turnstile.reset(this.widgetId);
        this.$wire.set(config.model, null, false);
    },

    destroy() {
        if (this.widgetId === null) return;

        window.turnstile.remove(this.widgetId);
        this.widgetId = null;
    },
});

/**
 * The browser half of "Translate this page", behind <livewire:livewire.page-translator>.
 *
 * Google's website translator does the work; this keeps its own bar out of
 * sight and drives it from the site's menu. The choice is kept in localStorage
 * and in the googtrans cookie Google reads, so it carries to every page. The
 * Google script is only fetched once somebody has chosen a language — a visitor
 * who never opens the menu never loads it.
 */

const KEY = "page-translate";

function read() {
    try {
        return localStorage.getItem(KEY) || "";
    } catch {
        return "";
    }
}

function write(code) {
    try {
        code ? localStorage.setItem(KEY, code) : localStorage.removeItem(KEY);
    } catch {
        //
    }
}

function fromCookie() {
    return (document.cookie.match(/(?:^|;\s*)googtrans=\/[^/]*\/([^;]+)/) || [])[1] || "";
}

/** Google reads the cookie on the bare host and on the dotted domain, so both are written and both are cleared. */
function cookie(source, code) {
    const value = code ? `/${source}/${code}` : "";
    const expiry = code ? "" : ";expires=Thu, 01 Jan 1970 00:00:00 GMT";

    document.cookie = `googtrans=${value};path=/${expiry}`;

    if (location.hostname.includes(".")) {
        document.cookie = `googtrans=${value};path=/;domain=.${location.hostname}${expiry}`;
    }
}

function load(source, languages) {
    if (window.google?.translate || document.getElementById("google-translate-script")) {
        return;
    }

    window.kGoogleTranslateInit = () =>
        new window.google.translate.TranslateElement(
            { pageLanguage: source, includedLanguages: languages.join(","), autoDisplay: false },
            "google_translate_element",
        );

    const script = document.createElement("script");
    script.id = "google-translate-script";
    script.src = "https://translate.google.com/translate_a/element.js?cb=kGoogleTranslateInit";
    document.body.appendChild(script);
}

export default function pageTranslator({ languages = [], source = "en" } = {}) {
    return {
        current: "",

        init() {
            const code = read() || fromCookie();

            if (!languages.includes(code)) {
                return;
            }

            this.current = code;
            write(code);
            cookie(source, code);
            load(source, languages);
        },

        choose(code) {
            // Google cannot reliably undo itself, so going back to the original
            // is a clean reload with the choice forgotten.
            if (!code) {
                write("");
                cookie(source, "");
                location.reload();

                return;
            }

            this.current = code;
            write(code);
            cookie(source, code);

            const combo = document.querySelector(".goog-te-combo");

            if (combo) {
                combo.value = code;
                combo.dispatchEvent(new Event("change"));
            } else {
                load(source, languages);
            }
        },
    };
}

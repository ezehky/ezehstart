/**
 * The browser half of a passkey ceremony, behind the passkey panel on the
 * security screens and the passkey button on the sign-in page.
 *
 * The server hands over a challenge, the browser asks the device to sign it
 * (fingerprint, face or PIN), and the signed answer goes back to the server.
 * Nothing secret travels in either direction: the private key never leaves the
 * device, which is the whole point of the thing.
 *
 * Both methods take the Livewire method names rather than hard-coding them, so the
 * same component drives enrolment on one screen and sign-in on another.
 */
import {
    browserSupportsWebAuthn,
    startAuthentication,
    startRegistration,
} from "@simplewebauthn/browser";

/**
 * What the device said, in words somebody can act on. A cancelled prompt is by
 * far the most common failure and is not really an error at all.
 */
function explain(error) {
    switch (error?.name) {
        case "NotAllowedError":
        case "AbortError":
            return "The passkey prompt was closed before it finished.";
        case "InvalidStateError":
            return "This device already holds a passkey for your account.";
        case "SecurityError":
            return "Passkeys need a secure (https) connection to this site.";
        default:
            return "Your device could not complete the passkey request.";
    }
}

export default () => ({
    supported: browserSupportsWebAuthn(),

    busy: false,

    error: null,

    /**
     * Create a key for the signed-in account. `optionsMethod` validates the form
     * and answers with the challenge, or null when validation failed — in which
     * case Livewire is already showing the error and there is nothing to prompt.
     */
    async register(optionsMethod = "passkeyOptions", storeMethod = "storePasskey") {
        await this.run(async () => {
            const options = await this.$wire.call(optionsMethod);

            if (!options) return;

            const credential = await startRegistration({
                optionsJSON: JSON.parse(options),
            });

            await this.$wire.call(storeMethod, JSON.stringify(credential));
        });
    },

    /**
     * Sign in with whichever key the device offers for this site.
     */
    async authenticate(optionsMethod = "passkeyOptions", loginMethod = "loginWithPasskey") {
        await this.run(async () => {
            const options = await this.$wire.call(optionsMethod);

            if (!options) return;

            const credential = await startAuthentication({
                optionsJSON: JSON.parse(options),
            });

            await this.$wire.call(loginMethod, JSON.stringify(credential));
        });
    },

    async run(ceremony) {
        if (this.busy) return;

        this.busy = true;
        this.error = null;

        try {
            await ceremony();
        } catch (error) {
            this.error = explain(error);
        } finally {
            this.busy = false;
        }
    },
});

<?php

namespace Database\Seeders;

use App\Enums\PolicyTypeEnum;
use App\Enums\StatusPolicy;
use App\Enums\StatusYes;
use App\Models\Policy;
use Illuminate\Database\Seeder;

/**
 * Publishes version 1.0 of each policy.
 *
 * This is a starting point, not legal advice. It describes what this starter kit
 * actually does — the accounts it creates, the data it stores, the cookies it
 * sets — so it is accurate about the software rather than generic. What it cannot
 * know is your company, your jurisdiction, your processors or your refund terms,
 * and every section is written to be edited. Have it reviewed before you take
 * money or handle anybody's data in production.
 *
 * Matched on type and version, so re-running refreshes the copy without stacking
 * up duplicate versions. Once a real 2.0 has been published, editing 1.0 here no
 * longer changes what the public page shows — that is the point of the versioning.
 *
 * Headings carry explicit `{#anchor}` markers. Legal pages get deep-linked from
 * contracts and emails nobody can go back and edit, so the anchors must survive
 * a reworded heading.
 */
class PolicySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->policies() as $policy) {
            Policy::query()->updateOrCreate(
                ['policy_type' => $policy['policy_type'], 'version' => '1.0'],
                [
                    ...$policy,
                    'status' => StatusPolicy::PUBLISHED,
                    'effective_at' => now(),
                ]
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function policies(): array
    {
        return [
            [
                'policy_type' => PolicyTypeEnum::TERMS,
                'title' => 'Terms of Service',
                'intro' => 'The rules that cover your account and your use of this service. Please read them before you register.',
                'requires_consent' => StatusYes::YES,
                'content' => $this->terms(),
            ],
            [
                'policy_type' => PolicyTypeEnum::PRIVACY,
                'title' => 'Privacy Policy',
                'intro' => 'What we collect, why we collect it, who we share it with, and the rights you have over your data.',
                'requires_consent' => StatusYes::YES,
                'content' => $this->privacy(),
            ],
            [
                'policy_type' => PolicyTypeEnum::COOKIES,
                'title' => 'Cookie Policy',
                'intro' => 'The cookies this site sets, what each one does, and how to control them. No advertising cookies are used.',
                'requires_consent' => StatusYes::NO,
                'content' => $this->cookies(),
            ],
        ];
    }

    protected function terms(): string
    {
        $site = $this->siteName();
        $contact = $this->contactEmail();

        return <<<MD
        ## 1. Agreement to these terms {#agreement}

        These Terms of Service govern your access to and use of {$site} (the "Service"), including this website and any account you hold on it.

        By creating an account or otherwise using the Service, you confirm that you have read, understood, and agree to be bound by these terms and by our [Privacy Policy](/privacy). If you do not agree, please do not create an account.

        ## 2. Your account {#accounts}

        You must be at least 16 years old to create an account, or have the consent of a parent or legal guardian.

        You are responsible for the accuracy of the details you give us, for keeping your password to yourself, and for everything that happens under your account. Tell us straight away if you believe somebody else has access to it.

        An account belongs to one person. Do not share it, sell it, or let somebody else sign in as you.

        ## 3. Acceptable use {#acceptable-use}

        You agree not to:

        - break any law, or use the Service to help anybody else break one
        - attempt to gain access to an account, system, or data that is not yours
        - probe, scan, or test the security of the Service without our written permission
        - interfere with the Service or place an unreasonable load on it, including by automated means
        - upload anything unlawful, misleading, defamatory, or that infringes somebody else's rights
        - misrepresent who you are or what your association with us is

        We may investigate anything we believe breaches this section, and take the steps set out in section 7.

        ## 4. Your content {#your-content}

        Anything you upload or submit stays yours. You keep every right in it that you had before.

        You give us the permission we need to host, store, back up, and display that content for the purpose of operating the Service and providing it to you. That permission ends when you delete the content or close your account, except where we are required to keep a copy by law or where it has already been shared with somebody else who has not deleted it.

        You are responsible for having the rights to whatever you upload.

        ## 5. Our content {#intellectual-property}

        The Service itself — the software, the design, the text we wrote, and our name and marks — belongs to us or to the people who licensed it to us. Using the Service does not transfer any of it to you.

        You may not copy, adapt, or redistribute any part of it except as these terms allow or the law permits.

        ## 6. Fees and payments {#payments}

        Where a part of the Service is paid for, the price, the billing period, and what is included are shown before you commit to it.

        Fees are payable in advance unless we say otherwise. Unless the law says otherwise, or we say so at the point of sale, payments are not refundable once the service they cover has begun.

        We may change our prices. A change never applies to a period you have already paid for, and we will tell you before it takes effect.

        > Replace this section with your own terms if you charge for anything. It is deliberately conservative and almost certainly does not describe your billing.

        ## 7. Suspension and termination {#termination}

        You can close your account at any time from your account settings.

        We may suspend or close an account that breaches these terms, that we are required to act on by law, or that presents a risk to other users or to the Service. Where it is reasonable to do so, we will tell you why and give you an opportunity to put it right first.

        Closing an account does not remove your obligation to pay anything you already owe, and the sections that by their nature should survive — your content licence, liability, and these terms as a whole — continue to apply.

        ## 8. Availability and changes {#availability}

        We work to keep the Service available, but we do not promise it will be uninterrupted or error-free. It may be unavailable during maintenance, or because of something outside our control.

        We may add to, change, or withdraw features. Where a change materially reduces what you get and you are paying for it, we will tell you in advance.

        ## 9. Liability {#liability}

        The Service is provided as it is. To the extent the law allows, we exclude all implied warranties.

        Nothing in these terms limits liability for death or personal injury caused by negligence, for fraud, or for anything else that cannot lawfully be limited.

        Subject to that, we are not liable for indirect or consequential loss, for lost profits, or for lost or corrupted data; and our total liability for any claim is limited to the amount you paid us in the twelve months before the claim arose.

        ## 10. Changes to these terms {#changes}

        We may publish a new version of these terms. When we do, the version in force is shown on this page along with the date it was published, and earlier versions stay readable.

        Where a change is material, we will ask you to accept it the next time you sign in. Continuing to use the Service after a change takes effect means you accept it.

        ## 11. Contact {#contact}

        Questions about these terms can go to [{$contact}](mailto:{$contact}).
        MD;
    }

    protected function privacy(): string
    {
        $site = $this->siteName();
        $contact = $this->contactEmail();

        return <<<MD
        ## 1. Who this covers {#who-we-are}

        This policy explains how {$site} handles personal data when you use this website and any account you hold on it. It applies to you whether you are signed in or not.

        > Add your legal entity name, registered address, and — if you have one — the contact details of your data protection officer or representative.

        ## 2. What we collect {#what-we-collect}

        **What you give us.** Your name, email address, and password when you register. Anything else you choose to add to your profile: a phone number, a date of birth, a gender, a short biography, and a profile picture.

        **What your use of the Service produces.** The IP address your account was created from, the times you sign in, and a record of the actions taken on your account — what changed, when, and what it changed from. We keep this to answer questions about an account later, including your own.

        **What your browser tells us.** Your IP address, your browser and device type, and the time zone your browser reports. Our [Cookie Policy](/cookies) covers what is stored on your device.

        **What you accept.** When you accept a policy that requires consent, we record which version you accepted, when, and the IP address and browser you accepted it from.

        We do not ask for payment card numbers. Where a payment is taken, it is handled by a payment provider and the card details do not reach us.

        ## 3. Why we use it {#why}

        - to create and run your account, and to sign you in
        - to send you the messages the Service has to send — verification codes, password resets, sign-in notices, and notifications you have turned on
        - to keep the Service secure, to investigate misuse, and to meet our legal obligations
        - to answer you when you contact us
        - to understand how the Service is used, so we can improve it

        We do not sell your personal data, and we do not use it to build advertising profiles.

        ## 4. Our legal grounds {#legal-bases}

        Where the UK or EU GDPR applies, we rely on:

        | Ground | What we use it for |
        | --- | --- |
        | Contract | Running your account and providing the Service you signed up for |
        | Legitimate interests | Security, fraud prevention, keeping records of what happened on an account, and improving the Service |
        | Consent | Optional notifications, and anything else we ask you to opt in to |
        | Legal obligation | Keeping records we are required to keep, and responding to lawful requests |

        Where we rely on consent, you can withdraw it at any time without affecting anything done before you did.

        ## 5. Who we share it with {#sharing}

        We share personal data only with:

        - service providers who run parts of the Service for us — hosting, email delivery, error monitoring, and payment processing — and only what each of them needs
        - professional advisers, where we need advice
        - authorities, where the law requires it
        - a buyer or successor, if the business is sold or reorganised

        Everyone in the first group is bound to use the data only on our instructions.

        > List your actual processors here, and say whether any of them are outside your country. People are entitled to know where their data goes.

        ## 6. How long we keep it {#retention}

        We keep your account data for as long as your account is open.

        When you delete your account, we remove or anonymise your personal details. Records we are required to keep — and records that would lose their meaning without the account they belong to, such as the log of what happened on it — are retained for as long as we need them, and no longer.

        ## 7. How we protect it {#security}

        Passwords are stored hashed and are never readable by us or by anybody else. Traffic to the Service is encrypted in transit. Access to production data is limited to the people who need it.

        No system is perfectly secure. If a breach affects you and the law requires us to tell you, we will.

        ## 8. Your rights {#your-rights}

        Depending on where you live, you may have the right to:

        - **access** the personal data we hold about you
        - **correct** it where it is wrong
        - **delete** it, in the circumstances the law provides for
        - **restrict** or **object to** how we use it
        - **receive a copy** of what you gave us, in a portable format
        - **withdraw consent** where we relied on it
        - **complain** to your data protection authority

        Much of this you can do yourself from your account settings. For anything else, write to [{$contact}](mailto:{$contact}) and we will respond within the time the law allows.

        ## 9. Children {#children}

        The Service is not intended for children under 16. If you believe a child has given us personal data, contact us and we will remove it.

        ## 10. Changes to this policy {#changes}

        We may publish a new version of this policy. The version in force is shown on this page with the date it was published, and earlier versions stay readable. Where a change is material, we will ask you to accept it the next time you sign in.

        ## 11. Contact {#contact}

        Questions, requests, and complaints about your data can go to [{$contact}](mailto:{$contact}).
        MD;
    }

    protected function cookies(): string
    {
        $site = $this->siteName();
        $contact = $this->contactEmail();

        return <<<MD
        ## 1. What cookies are {#what-cookies-are}

        A cookie is a small file a website asks your browser to store. It lets the site recognise your browser on your next request — which is what makes staying signed in possible at all.

        This page covers cookies and the similar storage a browser offers, such as local storage.

        ## 2. The cookies we set {#cookies-we-set}

        {$site} sets only what it needs to work. There are no advertising cookies and no third-party tracking cookies.

        | Cookie | What it does | How long it lasts |
        | --- | --- | --- |
        | Session cookie | Identifies your browsing session, so the site knows one request came from the same browser as the last. Also carries your reported time zone, so timestamps show in your local time before your account has a saved one. | Until you close your browser, or two hours of inactivity |
        | `XSRF-TOKEN` | Proves a form submission came from a page we served, which is what stops another site submitting forms as you. | Same as the session |
        | `remember_web_…` | Set only if you ask to be remembered when you sign in. Keeps you signed in on this device. | Up to five years, or until you sign out |
        | Appearance preference | Reusers whether you chose the light or dark theme. Stored in your browser rather than sent to us. | Until you clear your browser storage |

        The first three are strictly necessary: the Service cannot function without them, and they are not used to track you across other websites.

        ## 3. What we do not set {#no-third-party}

        We do not set advertising cookies, and we do not embed third-party trackers or social media pixels.

        > If you add analytics, a chat widget, an embedded video, or a payment provider's script, each one may set its own cookies. Add them to the table above and say what they do — this is exactly the section that goes stale first.

        ## 4. Controlling cookies {#controlling}

        Every major browser lets you see the cookies a site has set, delete them, and refuse new ones. The controls are usually under Settings → Privacy.

        Blocking the strictly necessary cookies above will stop you being able to sign in. Clearing them signs you out and returns the site to its defaults.

        ## 5. Changes {#changes}

        We may publish a new version of this policy. The version in force is shown on this page with the date it was published, and earlier versions stay readable.

        ## 6. Contact {#contact}

        Questions about cookies can go to [{$contact}](mailto:{$contact}).
        MD;
    }

    /**
     * The configured site name, falling back to the application name.
     */
    protected function siteName(): string
    {
        $name = kSiteConfig('name');

        return \is_string($name) && $name !== '' ? $name : (string) config('app.name');
    }

    /**
     * The configured support address. Written into the copy rather than left as a
     * placeholder, so a seeded policy has a real address on it from the start.
     */
    protected function contactEmail(): string
    {
        $email = kSiteConfig('contact-email');

        return \is_string($email) && $email !== '' ? $email : 'support@example.test';
    }
}

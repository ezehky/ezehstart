<?php

namespace Database\Seeders;

use App\Enums\FaqTypeEnum;
use App\Enums\StatusDefault;
use App\Models\Faq;
use Illuminate\Database\Seeder;

/**
 * The questions this starter kit can actually answer.
 *
 * Deliberately about the account rather than about a product: a kit that does not
 * know what you are building can only speak to signing in, security, and data,
 * and a seeded question about a product that does not exist is worse than none.
 * Replace them as soon as you have something of your own to explain.
 *
 * Seeded in display order and matched on the question itself, so re-running
 * refreshes the answers without duplicating any of them. Answers are markdown,
 * and links are written relative so they follow whichever site the seeder runs
 * against rather than baking in whatever APP_URL happened to be set.
 */
class FaqSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->faqs() as $order => $faq) {
            Faq::query()->updateOrCreate(
                ['question' => $faq['question']],
                [
                    ...$faq,
                    'faq_type' => FaqTypeEnum::GENERAL,
                    'flow_order' => $order + 1,
                    'status' => StatusDefault::ACTIVE,
                ]
            );
        }
    }

    /**
     * @return array<int, array{question: string, answer: string}>
     */
    protected function faqs(): array
    {
        return [
            [
                'question' => 'Do I need an account to get started?',
                'answer' => 'Yes. Registering takes an email address and a password, and you are signed in straight away. You can fill in the rest of your profile whenever you like.',
            ],
            [
                'question' => 'Can I sign in without a password?',
                'answer' => 'Yes. Choose **Sign in with an email code** and we send a six-digit code to your address. The code signs you in and, if you do not have an account yet, creates one. It is valid for a few minutes and can only be used once.',
            ],
            [
                'question' => 'Why do I have to verify my email address?',
                'answer' => 'So we know we can reach you — for password resets, sign-in notices, and anything else that has to arrive. We send a code when you register; if it does not turn up, check your spam folder and ask for a new one.',
            ],
            [
                'question' => 'I have forgotten my password. What now?',
                'answer' => 'Use [forgot password](/forgot-password) and we email you a link to set a new one. The link expires, so ask for a fresh one if you come back to it later.',
            ],
            [
                'question' => 'How do I change my email address or password?',
                'answer' => 'Both are under account settings once you are signed in. Changing either sends a notice to the address on the account, so an unexpected change never goes unnoticed.',
            ],
            [
                'question' => 'What happens if I sign in somewhere I do not recognise?',
                'answer' => 'Every sign-in sends a notice to your email address with the IP address it came from. If one was not you, change your password immediately and sign out of your other sessions from account settings.',
            ],
            [
                'question' => 'What do you do with my personal data?',
                'answer' => 'Only what is needed to run your account and keep it secure. We do not sell it and we do not use it for advertising. The [privacy policy](/privacy) sets out exactly what we collect, why, and how long we keep it.',
            ],
            [
                'question' => 'Can I delete my account?',
                'answer' => 'Yes, from account settings. Your personal details are removed or anonymised. Records we are required to keep are retained for as long as we need them and no longer — the [privacy policy](/privacy) explains which.',
            ],
            [
                'question' => 'Do you use tracking cookies?',
                'answer' => 'No. The only cookies set are the ones that make signing in work, plus your choice of light or dark theme. The [cookie policy](/cookies) lists every one of them.',
            ],
            [
                'question' => 'Who do I contact if something goes wrong?',
                'answer' => 'Use the contact details in the footer of any page. If it is about your account, tell us the email address on it — but never send us your password.',
            ],
        ];
    }
}

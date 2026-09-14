@props([
    'title' => '',
    'preheader' => '',
    'emailConfig' => [],

    // A signed unsubscribe link, from NewsletterService::unsubscribeUrl().
    //
    // Opt-in per mailable rather than always on: most of what this layout carries
    // is transactional — a sign-in code, a security alert, a receipt — and offering
    // to unsubscribe from those is offering something the site will not honour.
    // Only mail somebody subscribed to should pass it.
    'unsubscribeUrl' => null,
])

@php
    $siteName = $emailConfig['name'];
    $logo = $emailConfig['logo'] ?? null;
    $supportEmail = $emailConfig['contactEmail'] ?? $emailConfig['email'];
@endphp

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>{{ $title ?: $siteName }}</title>
    <x-layouts.email.theme />
</head>
<body>
    @if ($preheader)
        <div class="email-preheader">
            {{ $preheader }}
        </div>
    @endif

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="email-bg">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="email-shell">
                    <tr>
                        <td class="email-head">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td class="email-brand-cell">
                                        <a href="{{ route('home') }}" class="email-brand">
                                            @if ($logo)
                                                <img src="{{ $logo }}" alt="{{ $siteName }}" height="42" class="email-logo">
                                            @else
                                                {{ $siteName }}
                                            @endif
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            @if ($title)
                                <h1 class="email-title">
                                    {{ $title }}
                                </h1>
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                    <tr>
                                        <td class="email-rule">&nbsp;</td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="email-content">
                            {{ $slot }}
                        </td>
                    </tr>
                    <tr>
                        <td align="center" class="email-footer">
                            <p class="email-footer-name">
                                {{ $siteName }}
                            </p>
                            <p class="email-footer-text">
                                Need help? Contact us at
                                <a href="mailto:{{ $supportEmail }}" class="email-footer-link">
                                    {{ $supportEmail }}
                                </a>.
                            </p>
                            @if ($unsubscribeUrl)
                                <p class="email-footer-text">
                                    Not interested any more?
                                    <a href="{{ $unsubscribeUrl }}" class="email-footer-link">Unsubscribe</a>.
                                    Account and security emails are not affected.
                                </p>
                            @endif
                            <p class="email-footer-copy">&copy; {{ date('Y') }} {{ $siteName }}. All rights reserved.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

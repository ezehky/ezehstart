<x-layouts.email
    :$emailConfig
    title="Welcome, {{ $user->name }}"
    preheader="Your {{ $emailConfig['name'] }} account is ready."
>
    <p class="para">Hi {{ $user->name }},</p>

    @if ($otp)
        <p class="para">
            Your account is ready. Use the verification code below to confirm your email address before opening your dashboard.
        </p>

        <div class="otp-box">
            <div class="otp-label">Verification code</div>
            <div class="otp-code">{{ $otp }}</div>
            <div class="otp-expiry">This code expires in {{ $expiresInMinutes }} minutes.</div>
        </div>

        <table role="presentation" cellspacing="0" cellpadding="0" class="btn-row">
            <tr>
                <td class="btn-cell btn-cell-lime">
                    <a
                        href="{{ route('email.verification', ['user' => $user->email]) }}"
                        class="btn btn-dark">
                        Verify your email
                    </a>
                </td>
            </tr>
        </table>

        <p class="para-flush text-soft">
            We are glad to have you here. Keep this email for your records, and never share this code with anyone.
        </p>

    @else
        <p class="para">
            Your account is ready. You can now log in to your dashboard and start exploring the features of your {{ $emailConfig['name'] }} account.
        </p>

        <table role="presentation" cellspacing="0" cellpadding="0" class="btn-row">
            <tr>
                <td class="btn-cell btn-cell-lime">
                    <a
                        href="{{ route('login') }}"
                        class="btn btn-dark">
                        Log in to your dashboard
                    </a>
                </td>
            </tr>
        </table>

        <p class="para-flush text-soft">
            We are glad to have you here.
        </p>
    @endif
</x-layouts.email>

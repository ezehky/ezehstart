<x-layouts.email
    :$emailConfig
    title="New sign-in detected"
    preheader="A new login was recorded on your {{ $emailConfig['name'] }} account."
>
    <p class="para">Hi {{ $user->name }},</p>

    <p class="para-lg">
        We noticed a successful sign-in to your account. If this was you, no action is needed.
    </p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="info-panel">
        <tr>
            <td class="info-label">Login time</td>
            <td align="right" class="info-value">{{ $loginAt?->format('M d, Y h:ia') }}</td>
        </tr>
        <tr>
            <td class="info-label-last">IP address</td>
            <td align="right" class="info-value-last">{{ $ipAddress ?: 'Unavailable' }}</td>
        </tr>
    </table>

    <p class="para">
        If you do not recognize this sign-in, reset your password immediately to secure your account.
    </p>

    <table role="presentation" cellspacing="0" cellpadding="0" class="btn-row-flush">
        <tr>
            <td class="btn-cell btn-cell-forest">
                <a href="{{ route('password.request') }}" class="btn btn-light">
                    Reset password
                </a>
            </td>
        </tr>
    </table>
</x-layouts.email>

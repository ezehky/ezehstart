<x-layouts.email
    :$emailConfig
    title="Your account is scheduled for deletion"
    preheader="You have until {{ $scheduledAt->format('M d, Y') }} to change your mind."
>
    <p class="para">Hi {{ $user->name }},</p>

    <p class="para">
        We received a request to delete your {{ $emailConfig['name'] }} account. Nothing has been removed yet — the
        account stays exactly as it is until the date below, and you can sign in and use it normally in the meantime.
    </p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="info-panel">
        <tr>
            <td class="info-label">Requested on</td>
            <td align="right" class="info-value">{{ now()->format('M d, Y') }}</td>
        </tr>
        <tr>
            <td class="info-label-last">Deleted on</td>
            <td align="right" class="info-value-last">{{ $scheduledAt->format('M d, Y') }}</td>
        </tr>
    </table>

    <p class="para">
        If you did not ask for this, or you have changed your mind, use the button below and the account goes back to
        normal straight away.
    </p>

    <table role="presentation" cellspacing="0" cellpadding="0" class="btn-row">
        <tr>
            <td class="btn-cell btn-cell-lime">
                <a href="{{ $restoreUrl }}" class="btn btn-dark">
                    Keep my account
                </a>
            </td>
        </tr>
    </table>

    <p class="para-flush text-soft">
        We will write again before the date arrives. After it passes, your account and its data cannot be recovered.
    </p>
</x-layouts.email>

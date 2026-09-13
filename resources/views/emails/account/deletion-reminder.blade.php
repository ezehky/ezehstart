<x-layouts.email
    :$emailConfig
    title="Your account is deleted {{ $reminder->lead() }}"
    preheader="{{ $scheduledAt?->format('M d, Y') }} is the last day to keep your account."
>
    <p class="para">Hi {{ $user->name }},</p>

    <p class="para">
        Your {{ $emailConfig['name'] }} account is still scheduled for deletion, and the date is now
        {{ $reminder->lead() }}. Once it passes, the account and everything in it are gone for good — this is not
        something support can undo afterwards.
    </p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="info-panel">
        <tr>
            <td class="info-label-last">Deleted on</td>
            <td align="right" class="info-value-last">{{ $scheduledAt?->format('M d, Y') }}</td>
        </tr>
    </table>

    <p class="para">
        Want to keep it? One click is all it takes.
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
        If you meant to leave, there is nothing to do. We will take care of it on the day.
    </p>
</x-layouts.email>

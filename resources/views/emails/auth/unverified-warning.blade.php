<x-layouts.email
    :$emailConfig
    title="Verify your email address"
    preheader="Your {{ $emailConfig['name'] }} account will be removed if it stays unverified."
>
    <p class="para">Hi {{ $user->name }},</p>

    <p class="para">
        The email address on your {{ $emailConfig['name'] }} account has not been verified yet. To keep the account,
        please verify it within the next {{ $daysUntilDeletion }} {{ $daysUntilDeletion === 1 ? 'day' : 'days' }}.
    </p>

    <table role="presentation" cellspacing="0" cellpadding="0" class="btn-row">
        <tr>
            <td class="btn-cell btn-cell-lime">
                <a href="{{ route('email.verification', ['user' => $user->email]) }}" class="btn btn-dark">
                    Verify my email
                </a>
            </td>
        </tr>
    </table>

    <p class="para-flush text-soft">
        If it stays unverified, the account and everything in it will be deleted for good. If you never signed up,
        there is nothing to do — the account goes on its own.
    </p>
</x-layouts.email>

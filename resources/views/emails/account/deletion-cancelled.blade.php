<x-layouts.email
    :$emailConfig
    title="Your account is staying"
    preheader="The scheduled deletion has been cancelled."
>
    <p class="para">Hi {{ $user->name }},</p>

    <p class="para">
        The deletion scheduled for your {{ $emailConfig['name'] }} account has been cancelled. Nothing was removed and
        the account is back to normal — you can sign in and pick up where you left off.
    </p>

    <table role="presentation" cellspacing="0" cellpadding="0" class="btn-row">
        <tr>
            <td class="btn-cell btn-cell-lime">
                <a href="{{ route('login') }}" class="btn btn-dark">
                    Go to your dashboard
                </a>
            </td>
        </tr>
    </table>

    <p class="para-flush text-soft">
        If you did not cancel this yourself, somebody else has access to your account. Change your password now and
        review your sign-in activity.
    </p>
</x-layouts.email>

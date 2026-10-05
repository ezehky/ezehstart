<x-layouts.email
    :$emailConfig
    title="We missed you, {{ $user->firstName() }}"
    preheader="It has been {{ $daysAway }} days since your last visit to {{ $emailConfig['name'] }}."
>
    <p class="para">Hi {{ $user->firstName() }},</p>

    <p class="para">
        It has been a while since you last signed in to {{ $emailConfig['name'] }}. Your account is right where you
        left it, ready whenever you are.
    </p>

    <table role="presentation" cellspacing="0" cellpadding="0" class="btn-row">
        <tr>
            <td class="btn-cell btn-cell-lime">
                <a href="{{ route('login') }}" class="btn btn-dark">
                    Continue where you left off
                </a>
            </td>
        </tr>
    </table>

    <p class="para-flush text-soft">
        We only send this once while you are away, so it will not turn up again until after your next visit.
    </p>
</x-layouts.email>

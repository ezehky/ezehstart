<x-layouts.email
    :$emailConfig
    title="A change to your library"
    preheader="{{ $summary }}"
>
    <p class="para">Hi {{ $user->firstName() }},</p>

    <p class="para">
        Somebody at {{ $emailConfig['name'] }} made a change to the files in your library.
    </p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="info-panel">
        <tr>
            <td class="info-label-last">What changed</td>
            <td align="right" class="info-value-last">{{ $summary }}</td>
        </tr>
    </table>

    <table role="presentation" cellspacing="0" cellpadding="0" class="btn-row">
        <tr>
            <td class="btn-cell btn-cell-lime">
                <a href="{{ route('user.image-library') }}" class="btn btn-dark">
                    Open your library
                </a>
            </td>
        </tr>
    </table>

    <p class="para-flush text-soft">
        Your uploads are private to your account. If this change was not expected, reply to this
        email and we will look into it.
    </p>
</x-layouts.email>

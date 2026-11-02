<x-layouts.email
    :$emailConfig
    :title="'We have updated our '.$policy->title"
    :preheader="$policy->intro ?: 'A new version is now in force.'"
>
    <p class="para">Hi {{ $user->firstName() }},</p>

    <p class="para-lg">
        We have published a new version of our {{ $policy->title }}. It is in force from
        {{ ($policy->effective_at ?? now())->format('F j, Y') }}.
    </p>

    @if ($policy->intro)
        <p class="para">{{ $policy->intro }}</p>
    @endif

    <table role="presentation" cellspacing="0" cellpadding="0" class="btn-row">
        <tr>
            <td class="btn-cell btn-cell-lime">
                <a href="{{ $policy->policy_type->url() }}" class="btn btn-dark">Read the {{ $policy->title }}</a>
            </td>
        </tr>
    </table>

    <p class="para-flush text-soft">
        You are receiving this because you have an account with us — it is a notice about our service, not marketing.
        Earlier versions stay readable on the same page.
    </p>
</x-layouts.email>

<x-layouts.email
    :$emailConfig
    title="Email delivery works"
    preheader="This is a test from Configuration › Email delivery."
>
    <p class="para-lg">
        This email was sent through <strong>{{ $provider->label() }}</strong> from the Email delivery screen.
        If you are reading it, the site can send email — receipts, codes and reminders will arrive the same way.
    </p>

    <p class="para-flush text-soft">
        If it landed in spam, check that the sending domain is verified with {{ $provider->label() }} (SPF and DKIM records).
    </p>
</x-layouts.email>

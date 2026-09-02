<x-layouts.email
    :$emailConfig
    title="Email verification code"
    preheader="Use this six-digit code to verify your {{ $emailConfig['name'] }} account."
>
    <p class="para">Hi {{ $user->name }},</p>

    <p class="para-lg">
        Use the verification code below to confirm {{ $user->email }}.
    </p>

    <div class="otp-box">
        <div class="otp-label">Verification code</div>
        <div class="otp-code">{{ $otp }}</div>
    </div>

    <p class="para">
        This code expires in {{ $expiresInMinutes }} minutes. If you did not request it, you can safely ignore this email.
    </p>

    <p class="para-flush text-soft">
        For your security, never share this code with anyone.
    </p>
</x-layouts.email>

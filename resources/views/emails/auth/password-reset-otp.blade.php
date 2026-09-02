<x-layouts.email
    :$emailConfig
    title="Password reset code"
    preheader="Use this six-digit code to reset your {{ $emailConfig['name'] ?? config('app.name') }} password."
>
    <p class="para">Hi {{ $user->name }},</p>

    <p class="para-lg">
        Use the verification code below to reset the password for {{ $user->email }}.
    </p>

    <div class="otp-box">
        <div class="otp-label">Reset code</div>
        <div class="otp-code">{{ $otp }}</div>
    </div>

    <p class="para">
        This code expires in {{ $expiresInMinutes }} minutes. If you did not request it, you can safely ignore this email.
    </p>

    <p class="para-flush text-soft">
        For your security, never share this code with anyone.
    </p>
</x-layouts.email>

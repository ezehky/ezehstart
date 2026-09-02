<x-layouts.email
    :$emailConfig
    :title="$isNewAccount ? 'Confirm your email address' : 'Your sign-in code'"
    :preheader="'Use this six-digit code to '.($isNewAccount ? 'finish creating your' : 'sign in to your').' '.$emailConfig['name'].' account.'"
>
    <p class="para">Hi {{ $name }},</p>

    <p class="para-lg">
        @if ($isNewAccount)
            Use the code below to confirm {{ $email }} and finish creating your account.
        @else
            Use the code below to sign in as {{ $email }}. No password needed.
        @endif
    </p>

    <div class="otp-box">
        <div class="otp-label">{{ $isNewAccount ? 'Confirmation code' : 'Sign-in code' }}</div>
        <div class="otp-code">{{ $otp }}</div>
    </div>

    <p class="para">
        This code expires in {{ $expiresInMinutes }} minutes. If you did not request it, you can safely ignore this email
        &mdash; nobody can {{ $isNewAccount ? 'create the account' : 'sign in' }} without it.
    </p>

    <p class="para-flush text-soft">
        For your security, never share this code with anyone.
    </p>
</x-layouts.email>

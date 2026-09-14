<x-layouts.email
    :$emailConfig
    :unsubscribe-url="$unsubscribeUrl"
    title="{{ $post->title }}"
    preheader="{{ \Illuminate\Support\Str::limit(strip_tags((string) $post->excerpt), 120) }}"
>
    <p class="para">Hi {{ $user->firstName() }},</p>

    <p class="para">
        There is something new on the {{ $emailConfig['name'] }} blog.
    </p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="info-panel">
        <tr>
            <td class="info-label-last">Published</td>
            <td align="right" class="info-value-last">{{ $post->published_at?->format('M d, Y') }}</td>
        </tr>
    </table>

    <p class="para-lg"><strong>{{ $post->title }}</strong></p>

    <p class="para">{{ \Illuminate\Support\Str::limit(strip_tags((string) $post->excerpt), 240) }}</p>

    <table role="presentation" cellspacing="0" cellpadding="0" class="btn-row">
        <tr>
            <td class="btn-cell btn-cell-lime">
                <a href="{{ $url }}" class="btn btn-dark">
                    Read the post
                </a>
            </td>
        </tr>
    </table>

    <p class="para-flush text-soft">
        You are getting this because you are subscribed to announcements — from your account
        settings, or from the sign-up form on the site.
    </p>
</x-layouts.email>

@blaze

@props([
    'variant' => 'outline',
])

@php
$classes = Flux::classes('shrink-0')
    ->add(match ($variant) {
        'outline' => '[:where(&)]:size-6',
        'solid' => '[:where(&)]:size-6',
        'mini' => '[:where(&)]:size-5',
        'micro' => '[:where(&)]:size-4',
    });
@endphp

<svg
    {{ $attributes->class($classes) }}
    data-flux-icon
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 50 50"
    fill="currentColor"
    aria-hidden="true"
    data-slot="icon"
>
    <path d="M11 4C7.134 4 4 7.134 4 11L4 39C4 42.866 7.134 46 11 46L39 46C42.866 46 46 42.866 46 39L46 11C46 7.134 42.866 4 39 4L11 4zM13.085938 13L21.023438 13L26.660156 21.009766L33.5 13L36 13L27.789062 22.613281L37.914062 37L29.978516 37L23.4375 27.707031L15.5 37L13 37L22.308594 26.103516L13.085938 13zM16.914062 15L31.021484 35L34.085938 35L19.978516 15L16.914062 15z" />
</svg>

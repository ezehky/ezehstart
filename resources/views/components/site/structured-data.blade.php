{{-- One schema.org block from StructuredDataService, pushed into the page head.

        <x-site.structured-data :data="$structuredData" />

     The JSON is escaped for a script tag (no raw angle brackets or ampersands),
     so a post title or excerpt can never close the tag and start markup. --}}
@props(['data' => []])

@if (! empty($data))
    @push('structured-data')
        <script type="application/ld+json">{!! json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    @endpush
@endif

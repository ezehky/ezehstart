{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">
    <channel>
        <title>{{ $title }}</title>
        <link>{{ route('blog.index') }}</link>
        <description>{{ $description }}</description>
        <language>{{ $language }}</language>
        <lastBuildDate>{{ $updatedAt->toRssString() }}</lastBuildDate>
        <atom:link href="{{ route('blog.feed') }}" rel="self" type="application/rss+xml" />
@foreach ($posts as $post)
        <item>
            <title>{{ $post->title }}</title>
            <link>{{ route('blog.show', $post) }}</link>
            <guid isPermaLink="true">{{ route('blog.show', $post) }}</guid>
            <pubDate>{{ $post->published_at?->toRssString() }}</pubDate>
            <dc:creator>{{ $post->authorName() }}</dc:creator>
@foreach ($post->categories as $category)
            <category>{{ $category->name }}</category>
@endforeach
            <description>{{ $post->metaDescriptionOrDefault() }}</description>
@if ($post->image)
            <enclosure url="{{ $post->image->url() }}" type="{{ $post->image->mime_type }}" length="{{ $post->image->size }}" />
@endif
        </item>
@endforeach
    </channel>
</rss>

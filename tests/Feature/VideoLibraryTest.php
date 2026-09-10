<?php

use App\Enums\MediaVisibilityEnum;
use App\Enums\StatusPost;
use App\Enums\StatusYes;
use App\Enums\UserTypeEnum;
use App\Enums\VideoProviderEnum;
use App\Models\Post;
use App\Models\Video;
use App\Models\VideoFolder;
use App\Services\BlogService;
use App\Services\SiteConfigurationService;
use App\Services\VideoLibraryService;
use Livewire\Livewire;

beforeEach(function () {
    $this->member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);
    $this->admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);

    app(SiteConfigurationService::class)->update(initials: true);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// RESOLVING

test('every shape of link a share button hands out resolves to the same video', function (string $url) {
    $resolved = VideoProviderEnum::resolve($url);

    expect($resolved)->not->toBeNull()
        ->and($resolved['provider'])->toBe(VideoProviderEnum::YOUTUBE)
        ->and($resolved['id'])->toBe('dQw4w9WgXcQ');
})->with([
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'https://youtube.com/watch?v=dQw4w9WgXcQ&t=42s',
    'https://www.youtube.com/watch?list=PL123&v=dQw4w9WgXcQ',
    'https://youtu.be/dQw4w9WgXcQ',
    'https://www.youtube.com/shorts/dQw4w9WgXcQ',
    'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
]);

test('vimeo links resolve to the numeric id', function () {
    expect(VideoProviderEnum::resolve('https://vimeo.com/123456789'))
        ->toMatchArray(['provider' => VideoProviderEnum::VIMEO, 'id' => '123456789']);
});

test('a host with no case in the enum resolves to nothing', function (?string $url) {
    expect(VideoProviderEnum::resolve($url))->toBeNull();
})->with([
    'https://evil.test/embed/xyz',
    // The point of matching on the whole host rather than a substring: a domain
    // that merely contains an allowed one is not that provider.
    'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ',
    'https://notyoutube.com/watch?v=dQw4w9WgXcQ',
    'javascript:alert(1)',
    'not a url at all',
    null,
]);

test('the player url is built from the id and never from the pasted link', function () {
    $video = app(VideoLibraryService::class)->store(
        $this->member,
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PLsomething',
    );

    // The tracking parameters are gone because nothing kept the URL — the row
    // holds a provider and an id, and the URL is written again from those.
    expect($video->embedUrl())->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// ADDING

test('a video is recorded against the account that added it', function () {
    $video = app(VideoLibraryService::class)->store($this->member, 'https://vimeo.com/123456789', 'A talk');

    expect($video)->not->toBeNull()
        ->and($video->user_id)->toBe($this->member->id)
        ->and($video->provider)->toBe(VideoProviderEnum::VIMEO)
        ->and($video->video_id)->toBe('123456789')
        ->and($video->title)->toBe('A talk')
        ->and($video->visibility)->toBe(MediaVisibilityEnum::PRIVATE);
});

test('adding the same video twice returns the row already held rather than failing', function () {
    $service = app(VideoLibraryService::class);

    $first = $service->store($this->member, 'https://youtu.be/dQw4w9WgXcQ');
    $second = $service->store($this->member, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');

    expect($second->id)->toBe($first->id)
        ->and(Video::query()->count())->toBe(1);
});

test('two accounts may each hold their own row for the same video', function () {
    $service = app(VideoLibraryService::class);

    $service->store($this->member, 'https://youtu.be/dQw4w9WgXcQ');
    $service->store($this->admin, 'https://youtu.be/dQw4w9WgXcQ');

    expect(Video::query()->count())->toBe(2);
});

test('a url no provider serves is not stored', function () {
    expect(app(VideoLibraryService::class)->store($this->member, 'https://evil.test/embed/xyz'))->toBeNull()
        ->and(Video::query()->count())->toBe(0);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// VISIBILITY

test('a private video is invisible to everybody but its owner and an admin', function () {
    $video = app(VideoLibraryService::class)->store($this->member, 'https://youtu.be/dQw4w9WgXcQ');

    $stranger = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);

    expect($video->isVisibleTo($this->member))->toBeTrue()
        ->and($video->isVisibleTo($this->admin))->toBeTrue()
        ->and($video->isVisibleTo($stranger))->toBeFalse()
        ->and($video->isVisibleTo(null))->toBeFalse();
});

test('a type video reaches everybody of that type', function () {
    $video = app(VideoLibraryService::class)->store(
        $this->admin,
        'https://youtu.be/dQw4w9WgXcQ',
        visibility: MediaVisibilityEnum::TYPE,
        visibleToType: UserTypeEnum::USER,
    );

    expect($video->isVisibleTo($this->member))->toBeTrue();
});

test('changing away from role clears the role, so it cannot be restored by accident', function () {
    $service = app(VideoLibraryService::class);

    $video = $service->store(
        $this->admin,
        'https://youtu.be/dQw4w9WgXcQ',
        visibility: MediaVisibilityEnum::TYPE,
        visibleToType: UserTypeEnum::USER,
    );

    $service->update($video, $video->title, visibility: MediaVisibilityEnum::PRIVATE);

    expect($video->fresh()->visible_to_type)->toBeNull();
});

test('the library query offers only what the account may see', function () {
    $service = app(VideoLibraryService::class);

    $service->store($this->member, 'https://youtu.be/dQw4w9WgXcQ');
    $service->store($this->admin, 'https://vimeo.com/123456789', visibility: MediaVisibilityEnum::PUBLIC);
    $service->store($this->admin, 'https://vimeo.com/987654321');

    $stranger = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);

    // The public one only: the member's is theirs, and the admin's private one is
    // nobody else's business.
    expect($service->libraryQuery($stranger)->pluck('video_id')->all())->toBe(['123456789'])
        // An administrator browses the lot — the library is also the site's media
        // manager, and one that hides rows from them is not a manager.
        ->and($service->libraryQuery($this->admin)->count())->toBe(3);
});

test('a folder somebody may not browse still does not hide a video they may see', function () {
    $service = app(VideoLibraryService::class);

    $folder = $service->createFolder($this->admin, 'Private folder');

    $service->store(
        $this->admin,
        'https://youtu.be/dQw4w9WgXcQ',
        folder: $folder,
        visibility: MediaVisibilityEnum::PUBLIC,
    );

    expect($service->libraryQuery($this->member)->count())->toBe(1);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// FOLDERS

test('deleting a folder keeps its videos and moves them to the root', function () {
    $service = app(VideoLibraryService::class);

    $folder = $service->createFolder($this->member, 'Tutorials');
    $video = $service->store($this->member, 'https://youtu.be/dQw4w9WgXcQ', folder: $folder);

    $service->deleteFolder($folder);

    expect(Video::query()->whereKey($video->id)->exists())->toBeTrue()
        ->and($video->fresh()->video_folder_id)->toBeNull();
});

test('deleting a parent folder promotes its children rather than taking the subtree', function () {
    $service = app(VideoLibraryService::class);

    $parent = $service->createFolder($this->member, 'Tutorials');
    $child = $service->createFolder($this->member, 'Advanced', $parent);

    $service->deleteFolder($parent);

    expect(VideoFolder::query()->whereKey($child->id)->exists())->toBeTrue()
        ->and($child->fresh()->parent_id)->toBeNull();
});

test('a member cannot make a shared folder even by asking for one', function () {
    $folder = app(VideoLibraryService::class)->createFolder($this->member, 'Mine', shared: true);

    expect($folder->user_id)->toBe($this->member->id);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE DELETE GUARD

test('an attached video refuses to be deleted', function () {
    $service = app(VideoLibraryService::class);

    $video = $service->store($this->member, 'https://youtu.be/dQw4w9WgXcQ');

    $post = Post::query()->create([
        'user_id' => $this->admin->id,
        'title' => 'A post',
        'slug' => 'a-post',
        'excerpt' => 'Short.',
        'content' => '<p>Long.</p>',
        'is_featured' => StatusYes::NO,
        'status' => StatusPost::PUBLISHED,
        'published_at' => now(),
    ]);

    $service->attach($video, $post, 'body');

    expect($service->delete($video))->toContain('used in 1 place')
        ->and(Video::query()->whereKey($video->id)->exists())->toBeTrue();

    // Released, it goes.
    $service->detach($post, 'body');

    expect($service->delete($video->fresh()))->toBeNull()
        ->and(Video::query()->whereKey($video->id)->exists())->toBeFalse();
});

test('usages are rebuilt from the saved html, so an embed removed from the body releases its video', function () {
    $service = app(VideoLibraryService::class);

    $video = $service->store($this->member, 'https://youtu.be/dQw4w9WgXcQ');

    $post = Post::query()->create([
        'user_id' => $this->admin->id,
        'title' => 'A post',
        'slug' => 'a-post',
        'excerpt' => 'Short.',
        'content' => '<p>Long.</p>',
        'is_featured' => StatusYes::NO,
        'status' => StatusPost::PUBLISHED,
        'published_at' => now(),
    ]);

    $withVideo = app(BlogService::class)->sanitize(
        '<p>Watch</p><iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"></iframe>'
    );

    $service->syncFromHtml($withVideo, $post, 'body');

    expect($video->fresh()->isAttached())->toBeTrue();

    // The author takes the embed out again.
    $service->syncFromHtml('<p>Never mind.</p>', $post, 'body');

    expect($video->fresh()->isAttached())->toBeFalse();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// SANITISING

test('an embed from an allowed provider survives the sanitiser', function () {
    $clean = app(BlogService::class)->sanitize(
        '<p>Watch this</p><iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"></iframe>'
    );

    expect($clean)->toContain('<iframe')
        ->and($clean)->toContain('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->and($clean)->toContain('<p>Watch this</p>');
});

test('a watch link pasted straight into the html is rewritten to the player url', function () {
    $clean = app(BlogService::class)->sanitize(
        '<iframe src="https://www.youtube.com/watch?v=dQw4w9WgXcQ&amp;t=30"></iframe>'
    );

    expect($clean)->toContain('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->and($clean)->not->toContain('watch?v=');
});

test('an iframe pointing at a host we do not allow is dropped entirely', function (string $html) {
    $clean = app(BlogService::class)->sanitize("<p>Before</p>{$html}<p>After</p>");

    expect($clean)->not->toContain('<iframe')
        ->and($clean)->toContain('<p>Before</p>')
        ->and($clean)->toContain('<p>After</p>');
})->with([
    '<iframe src="https://evil.test/embed/xyz"></iframe>',
    '<iframe src="https://youtube.com.evil.test/embed/xyz"></iframe>',
    '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
    '<iframe></iframe>',
]);

test('none of the author own attributes survive on an embed', function () {
    $clean = app(BlogService::class)->sanitize(
        '<iframe src="https://youtu.be/dQw4w9WgXcQ" onload="steal()" sandbox="allow-scripts allow-same-origin" '
        .'srcdoc="<script>alert(1)</script>" style="position:fixed;inset:0;z-index:99999"></iframe>'
    );

    // The tag is rebuilt from the provider and the id, so there is nothing of the
    // author's left on it to have to strip individually.
    expect($clean)->not->toContain('onload')
        ->and($clean)->not->toContain('sandbox')
        ->and($clean)->not->toContain('srcdoc')
        ->and($clean)->not->toContain('style=')
        ->and($clean)->toContain('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE SCREENS

test('the library page lists what the account may see and nothing else', function () {
    $service = app(VideoLibraryService::class);

    $mine = $service->store($this->member, 'https://youtu.be/dQw4w9WgXcQ', 'Mine');
    $theirs = $service->store($this->admin, 'https://vimeo.com/123456789', 'Theirs');

    Livewire::actingAs($this->member)
        ->test('pages::shared.video-library')
        ->assertSee('Mine')
        ->assertDontSee('Theirs');

    expect($mine->id)->not->toBe($theirs->id);
});

test('a video is added from the page by pasting a link', function () {
    Livewire::actingAs($this->member)
        ->test('pages::shared.video-library')
        ->set('video_url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
        ->set('new_title', 'A talk')
        ->call('addVideo')
        ->assertHasNoErrors();

    expect(Video::query()->where('title', 'A talk')->exists())->toBeTrue();
});

test('a link from a host we do not allow is refused with a message naming the ones we do', function () {
    Livewire::actingAs($this->member)
        ->test('pages::shared.video-library')
        ->set('video_url', 'https://evil.test/embed/xyz')
        ->call('addVideo')
        ->assertHasErrors('video_url');

    expect(Video::query()->count())->toBe(0);
});

test('a length typed on the edit panel is kept and read back as minutes and seconds', function () {
    $video = app(VideoLibraryService::class)->store($this->member, 'https://youtu.be/dQw4w9WgXcQ');

    Livewire::actingAs($this->member)
        ->test('pages::shared.video-library')
        ->set('selected', [$video->id])
        ->call('openPanel', 'edit')
        ->set('duration', 212)
        ->call('saveVideo')
        ->assertHasNoErrors();

    expect($video->fresh()->readableDuration())->toBe('3:32');
});

test('a length past an hour is padded, so it does not read as minutes', function () {
    $video = app(VideoLibraryService::class)->store($this->member, 'https://youtu.be/dQw4w9WgXcQ');

    $video->duration = 3903;

    expect($video->readableDuration())->toBe('1:05:03');
});

test('a member cannot edit somebody else video through the page', function () {
    $video = app(VideoLibraryService::class)->store(
        $this->admin,
        'https://youtu.be/dQw4w9WgXcQ',
        visibility: MediaVisibilityEnum::PUBLIC,
    );

    Livewire::actingAs($this->member)
        ->test('pages::shared.video-library')
        ->set('edit_id', $video->id)
        ->set('title', 'Hijacked')
        ->set('visibility', MediaVisibilityEnum::PUBLIC->value)
        ->call('saveVideo')
        ->assertForbidden();

    expect($video->fresh()->title)->not->toBe('Hijacked');
});

test('the picker hands the editor a player url and no slot', function () {
    $video = app(VideoLibraryService::class)->store($this->member, 'https://youtu.be/dQw4w9WgXcQ');

    Livewire::actingAs($this->member)
        ->test('lv.video-picker')
        ->call('open', false, null, null, null)
        ->call('toggle', $video->id)
        ->assertDispatched('video-picked', url: 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
});

test('a pick made for a named slot does not also reach the editor', function () {
    $video = app(VideoLibraryService::class)->store($this->member, 'https://youtu.be/dQw4w9WgXcQ');

    Livewire::actingAs($this->member)
        ->test('lv.video-picker')
        ->call('open', false, null, 'trailer', null)
        ->call('toggle', $video->id)
        // A trailer chosen for a form must not also drop itself into the body
        // being written.
        ->assertNotDispatched('video-picked')
        ->assertDispatched('videosSelected');
});

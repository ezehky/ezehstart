<?php

use App\Enums\GateAccessEnum;
use App\Enums\SocialHandleEnum;
use App\Enums\UserTypeEnum;
use App\Models\Post;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\BlogService;
use App\Services\RoleService;
use Livewire\Livewire;

/**
 * An author on the shipped author role, plus somebody to keep the lockout guard quiet.
 */
function authorAccount(array $attributes = []): User
{
    userOfType(UserTypeEnum::ADMIN, ['email' => 'keeper-'.fake()->unique()->numberBetween(1, 99999).'@example.test']);

    return userOfType(
        UserTypeEnum::ADMIN,
        ['email' => 'author-'.fake()->unique()->numberBetween(1, 99999).'@example.test', ...$attributes],
        app(RoleService::class)->authorRole(),
    );
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// WHO IS AN AUTHOR

test('the author role makes an account an author', function () {
    $author = authorAccount();
    $other = userOfType(UserTypeEnum::ADMIN, ['email' => 'plain@example.test']);

    expect($author->isAuthor())->toBeTrue()
        ->and($other->isAuthor())->toBeFalse();
});

test('a switched-off author role stops making somebody an author', function () {
    $author = authorAccount();
    $role = app(RoleService::class)->authorRole();

    app(RoleService::class)->update($role, 'Author', null, active: false);

    expect($author->fresh()->isAuthor())->toBeFalse();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// OWN POSTS ONLY

test('an author is held to the posts they wrote', function () {
    $author = authorAccount();
    $someoneElse = userOfType(UserTypeEnum::ADMIN, ['email' => 'other@example.test']);

    $mine = blogPost(['user_id' => $author->id, 'slug' => 'mine']);
    blogPost(['user_id' => $someoneElse->id, 'title' => 'Not mine', 'slug' => 'not-mine']);

    $service = app(BlogService::class);

    $this->actingAs($author);

    expect($service->authorRestricted($author))->toBeTrue()
        ->and($service->authorScope(Post::query(), $author)->pluck('id')->all())->toBe([$mine->id]);
});

test('an author holding full access to the blog is held to nothing', function () {
    $author = authorAccount();
    $role = app(RoleService::class)->authorRole();

    $role->gates = ['content.blogs' => GateAccessEnum::FULL->value];
    $role->save();

    $this->actingAs($author->fresh());

    expect(app(BlogService::class)->authorRestricted($author->fresh()))->toBeFalse();
});

test('an admin who is not an author sees the whole blog', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);
    blogPost(['user_id' => null]);

    $this->actingAs($admin);

    expect(app(BlogService::class)->authorRestricted($admin))->toBeFalse()
        ->and(app(BlogService::class)->authorScope(Post::query(), $admin)->count())->toBe(1);
});

test('the posts listing shows an author only their own', function () {
    $author = authorAccount();
    $someoneElse = userOfType(UserTypeEnum::ADMIN, ['email' => 'other@example.test']);

    blogPost(['user_id' => $author->id, 'title' => 'Mine to edit', 'slug' => 'mine']);
    blogPost(['user_id' => $someoneElse->id, 'title' => 'Somebody elses', 'slug' => 'theirs']);

    Livewire::actingAs($author)
        ->test('pages::admin.content.posts')
        ->assertSee('Mine to edit')
        ->assertDontSee('Somebody elses');
});

test('an author cannot open the editor on a post they did not write', function () {
    $author = authorAccount();
    $someoneElse = userOfType(UserTypeEnum::ADMIN, ['email' => 'other@example.test']);
    $theirs = blogPost(['user_id' => $someoneElse->id, 'slug' => 'theirs']);

    $this->actingAs($author)
        ->get(route('admin.blog.edit', $theirs))
        ->assertNotFound();
});

test('an author opens the editor on their own post', function () {
    $author = authorAccount();
    $mine = blogPost(['user_id' => $author->id, 'slug' => 'mine']);

    $this->actingAs($author)
        ->get(route('admin.blog.edit', $mine))
        ->assertOk();
});

test('a post with no author left is nobody to claim', function () {
    $author = authorAccount();
    $orphan = blogPost(['user_id' => null]);

    $this->actingAs($author);

    expect(app(BlogService::class)->editBlockedReason($orphan, $author))
        ->toContain('only work on posts you wrote');
});

test('an author on the shipped role cannot delete posts at all', function () {
    // Not an oversight in the role's map: deleting asks for FULL, and holding FULL is
    // exactly what lifts the ownership rule. So an author writes and edits their own
    // posts, and clearing the archive out stays an editor's job.
    $author = authorAccount();
    $mine = blogPost(['user_id' => $author->id, 'slug' => 'mine']);

    expect(kGate('content.blogs', GateAccessEnum::FULL, $author))->toBeFalse();

    Livewire::actingAs($author)
        ->test('pages::admin.content.posts')
        ->call('confirmDelete', $mine->id)
        ->call('delete');

    expect($mine->fresh())->not->toBeNull();
});

test('an author given full access can delete, and is no longer held to their own posts', function () {
    $author = authorAccount();
    $role = app(RoleService::class)->authorRole();

    $role->gates = ['content.blogs' => GateAccessEnum::FULL->value];
    $role->save();

    $someoneElse = userOfType(UserTypeEnum::ADMIN, ['email' => 'other@example.test']);
    $theirs = blogPost(['user_id' => $someoneElse->id, 'slug' => 'theirs']);

    $author = $author->fresh();

    expect(app(BlogService::class)->authorRestricted($author))->toBeFalse();

    Livewire::actingAs($author)
        ->test('pages::admin.content.posts')
        ->call('confirmDelete', $theirs->id)
        ->call('delete');

    expect($theirs->fresh())->toBeNull();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE AUTHOR PROFILE

test('an author saves a bio and social handles', function () {
    $author = authorAccount();

    Livewire::actingAs($author)
        ->test('pages::shared.profile')
        ->set('bio', 'Writes about skincare.')
        ->set('socials.'.SocialHandleEnum::X->value, '@someone')
        ->set('socials.'.SocialHandleEnum::LINKEDIN->value, 'https://linkedin.com/in/someone')
        ->call('saveAuthorProfile')
        ->assertHasNoErrors();

    $profile = UserProfile::query()->where('user_id', $author->id)->firstOrFail();

    expect($profile->bio)->toBe('Writes about skincare.')
        ->and($profile->socialsArray())->toBe([
            SocialHandleEnum::X->value => '@someone',
            SocialHandleEnum::LINKEDIN->value => 'https://linkedin.com/in/someone',
        ]);
});

test('a blank handle is dropped rather than stored empty', function () {
    $author = authorAccount();

    Livewire::actingAs($author)
        ->test('pages::shared.profile')
        ->set('bio', 'A bio.')
        ->set('socials.'.SocialHandleEnum::X->value, '   ')
        ->call('saveAuthorProfile');

    $profile = UserProfile::query()->where('user_id', $author->id)->firstOrFail();

    expect($profile->socialsArray())->toBe([]);
});

test('the author card is not offered to an account that does not write', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);

    Livewire::actingAs($admin)
        ->test('pages::shared.profile')
        ->assertDontSee('Author profile');
});

test('a handle is turned into a link and a full URL is left alone', function () {
    $profile = new UserProfile([
        'socials' => [
            SocialHandleEnum::X->value => '@someone',
            SocialHandleEnum::LINKEDIN->value => 'https://linkedin.com/in/someone',
        ],
    ]);

    expect(collect($profile->socialLinks())->pluck('url')->all())->toBe([
        'https://x.com/someone',
        'https://linkedin.com/in/someone',
    ]);
});

test('only personal platforms are offered, never the site support desk', function () {
    $values = collect(SocialHandleEnum::profiles())->map(fn (SocialHandleEnum $case) => $case->value);

    expect($values)->toContain(SocialHandleEnum::X->value)
        ->and($values)->not->toContain(SocialHandleEnum::WHATSAPP_SUPPORT->value)
        ->and($values)->not->toContain(SocialHandleEnum::TELEGRAM_CHANNEL->value);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE PUBLIC BYLINE

test('the post page carries the author bio and links', function () {
    $author = authorAccount(['name' => 'Ada Writer']);

    UserProfile::query()->create([
        'user_id' => $author->id,
        'bio' => 'Writes about skincare.',
        'socials' => [SocialHandleEnum::X->value => '@ada'],
    ]);

    $post = blogPost(['user_id' => $author->id, 'slug' => 'a-post']);

    $this->get(route('blog.show', $post))
        ->assertOk()
        ->assertSee('Written by')
        ->assertSee('Ada Writer')
        ->assertSee('Writes about skincare.')
        ->assertSee('https://x.com/ada');
});

test('an author with nothing written about them gets no card', function () {
    $author = authorAccount(['name' => 'Quiet Writer']);
    $post = blogPost(['user_id' => $author->id, 'slug' => 'a-post']);

    $this->get(route('blog.show', $post))
        ->assertOk()
        ->assertDontSee('Written by');
});

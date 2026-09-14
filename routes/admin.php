<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::admin.dashboard')->name('dashboard');
Route::livewire('/profile', 'pages::shared.profile')->name('profile');

// Site Configuration
Route::prefix('/site-config')->name('config.')->group(function () {
    Route::livewire('/info', 'pages::admin.configs.site-config')->name('site');
    Route::livewire('/security', 'pages::admin.configs.security')->name('security');
    Route::livewire('/preferences', 'pages::admin.configs.preferences')->name('preferences');
    Route::livewire('/email-senders', 'pages::admin.configs.email-senders')->name('email-senders');
    Route::livewire('/social-handles', 'pages::admin.configs.social-handles')->name('social-handles');
    Route::livewire('/json', 'pages::admin.configs.json-editor')->name('json');
    Route::livewire('/notification-types', 'pages::admin.configs.notification-types')->name('notification-types');
});

// Static Content
Route::prefix('/static')->name('static.')->group(function () {
    Route::livewire('/policies', 'pages::admin.static.policies')->name('policies');
    Route::livewire('/faqs', 'pages::admin.static.faqs')->name('faqs');
});

// Image library. The same screen in both workspaces — the service decides what
// each account may see, so there is nothing workspace-specific in the page.
Route::livewire('/image-library', 'pages::shared.image-library')->name('image-library');

// One member's library. Their uploads are private and deliberately absent from the
// admin library and picker, so this is the only way in — reached from the user list
// or from the account page, never by browsing.
Route::livewire('/image-library/{user}', 'pages::shared.image-library')->name('user-image-library');

// Video library. Embeds rather than uploads, but the same screen in both
// workspaces for the same reason — the service decides what each account sees.
Route::livewire('/video-library', 'pages::shared.video-library')->name('video-library');

// The same again for one member's video references.
Route::livewire('/video-library/{user}', 'pages::shared.video-library')->name('user-video-library');

// Money
Route::livewire('/transactions', 'pages::admin.transactions')->name('transactions');

// Blog. The editor is its own screen rather than a modal: a post is long-form,
// and a rich-text editor inside a dialog fights the page for scroll.
Route::livewire('/categories/{category_group}', 'pages::admin.content.categories')->name('categories');
Route::livewire('/tags', 'pages::admin.content.tags')->name('tags');
Route::prefix('blog')->name('blog.')->group(function () {
    Route::livewire('/posts', 'pages::admin.content.posts')->name('blogs');
    Route::livewire('/posts/new', 'pages::admin.content.post-edit')->name('create');
    Route::livewire('/posts/{post}/edit', 'pages::admin.content.post-edit')->name('edit');
});

// Email Marketing. Campaign details/builder/recipients/review is one wizard over a
// single growing record rather than four routes — see ⚡campaign-builder.blade.php.
Route::prefix('marketing')->name('marketing.')->group(function () {
    Route::livewire('/campaigns', 'pages::admin.marketing.campaigns')->name('campaigns');
    Route::livewire('/campaigns/new', 'pages::admin.marketing.campaign-builder')->name('campaigns.create');
    Route::livewire('/campaigns/{campaign}/edit', 'pages::admin.marketing.campaign-builder')->name('campaigns.edit');

    Route::livewire('/templates', 'pages::admin.marketing.templates')->name('templates');
    Route::livewire('/templates/new', 'pages::admin.marketing.template-builder')->name('templates.create');
    Route::livewire('/templates/{template}/edit', 'pages::admin.marketing.template-builder')->name('templates.edit');

    Route::livewire('/sections', 'pages::admin.marketing.sections')->name('sections');
    Route::livewire('/sections/new', 'pages::admin.marketing.section-editor')->name('sections.create');
    Route::livewire('/sections/{section}/edit', 'pages::admin.marketing.section-editor')->name('sections.edit');

    Route::livewire('/sent', 'pages::admin.marketing.sent')->name('sent');
    Route::livewire('/sent/{campaign}', 'pages::admin.marketing.sent-view')->name('sent.show');

    Route::livewire('/settings', 'pages::admin.marketing.settings')->name('settings');
});

// User Management Routes
Route::livewire('/admins', 'pages::admin.users.admins')->name('admins');
Route::livewire('/users', 'pages::admin.users.users')->name('users');
Route::livewire('/roles', 'pages::admin.users.roles')->name('roles');
Route::livewire('/activity-logs', 'pages::admin.users.activity-logs')->name('activity-logs');

// Who gets the newsletter. Accounts and address-only rows in one listing, because
// the send does not tell them apart — see NewsletterService.
Route::livewire('/newsletter', 'pages::admin.users.newsletter')->name('newsletter');

// Accounts that reached the end of their deletion window and were anonymized rather
// than removed. Soft-deleted rows, so every other screen's default scope hides them —
// this is the only way to see or finish removing one.
Route::livewire('/deleted-accounts', 'pages::admin.users.trashed-accounts')->name('deleted-accounts');
Route::livewire('/user/{user}', 'pages::admin.users.user-view')->name('user');

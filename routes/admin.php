<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::admin.dashboard')->name('dashboard');
Route::livewire('/profile', 'pages::shared.profile')->name('profile');

// Site Configuration
Route::prefix('/site-config')->name('config.')->group(function () {
    Route::livewire('/info', 'pages::admin.configs.site-config')->name('site');
    Route::livewire('/security', 'pages::admin.configs.security')->name('security');
    Route::livewire('/social-handles', 'pages::admin.configs.social-handles')->name('social-handles');
    Route::livewire('/json', 'pages::admin.configs.json-editor')->name('json');
    Route::livewire('/policies', 'pages::admin.configs.policies')->name('policies');
    Route::livewire('/faqs', 'pages::admin.configs.faqs')->name('faqs');
    Route::livewire('/notification-types', 'pages::admin.configs.notification-types')->name('notification-types');
});

// Image library. The same screen in both workspaces — the service decides what
// each account may see, so there is nothing workspace-specific in the page.
Route::livewire('/image-library', 'pages::shared.image-library')->name('image-library');

// Video library. Embeds rather than uploads, but the same screen in both
// workspaces for the same reason — the service decides what each account sees.
Route::livewire('/video-library', 'pages::shared.video-library')->name('video-library');

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

// User Management Routes
Route::livewire('/admins', 'pages::admin.users.admins')->name('admins');
Route::livewire('/users', 'pages::admin.users.users')->name('users');
Route::livewire('/roles', 'pages::admin.users.roles')->name('roles');
Route::livewire('/activity-logs', 'pages::admin.users.activity-logs')->name('activity-logs');
Route::livewire('/user/{user}', 'pages::admin.users.user-view')->name('user');

<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::admin.dashboard')->name('dashboard');
Route::livewire('/profile', 'pages::shared.profile')->name('profile');

// Site Configuration
Route::livewire('/site-config-set', 'pages::admin.configs.site-config')->name('site-config');
Route::livewire('/site-config/social-handles', 'pages::admin.configs.social-handles')->name('config.social-handles');
Route::livewire('/site-config/policies', 'pages::admin.configs.policies')->name('config.policies');
Route::livewire('/site-config/faqs', 'pages::admin.configs.faqs')->name('config.faqs');
Route::livewire('/site-config/notification-types', 'pages::admin.configs.notification-types')->name('config.notification-types');

// Image library. The same screen in both workspaces — the service decides what
// each account may see, so there is nothing workspace-specific in the page.
Route::livewire('/image-library', 'pages::shared.image-library')->name('image-library');

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
Route::livewire('/members', 'pages::admin.users.members')->name('members');
Route::livewire('/unassigned', 'pages::admin.users.unassigned')->name('unassigned');
Route::livewire('/roles', 'pages::admin.users.roles')->name('roles');
Route::livewire('/activity-logs', 'pages::admin.users.activity-logs')->name('activity-logs');
Route::livewire('/user/{user}', 'pages::admin.users.user-view')->name('user');

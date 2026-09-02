<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::admin.dashboard')->name('dashboard');
Route::livewire('/profile', 'pages::shared.profile')->name('profile');

// Site Configuration
Route::livewire('/site-config-set', 'pages::admin.configs.site-config')->name('site-config');
Route::livewire('/site-config/social-handles', 'pages::admin.configs.social-handles')->name('config.social-handles');

// User Management Routes
Route::livewire('/admins', 'pages::admin.users.admins')->name('admins');
Route::livewire('/members', 'pages::admin.users.members')->name('members');
Route::livewire('/unassigned', 'pages::admin.users.unassigned')->name('unassigned');
Route::livewire('/roles', 'pages::admin.users.roles')->name('roles');
Route::livewire('/activity-logs', 'pages::admin.users.activity-logs')->name('activity-logs');
Route::livewire('/user/{user}', 'pages::admin.users.user-view')->name('user');

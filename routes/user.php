<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::user.dashboard')->name('dashboard');

// Account
Route::livewire('/profile', 'pages::shared.profile')->name('profile');
Route::livewire('/account-settings', 'pages::user.account.account-settings')->name('account-settings');
Route::livewire('/security-settings', 'pages::user.account.security-settings')->name('security-settings');
Route::livewire('/delete-account', 'pages::user.account.delete-account')->name('delete-account');

// Money
Route::livewire('/transactions', 'pages::user.transactions')->name('transactions');

// Image library. Same screen as the admin workspace; the library query is what
// keeps one member's uploads out of another's picker.
Route::livewire('/image-library', 'pages::shared.image-library')->name('image-library');

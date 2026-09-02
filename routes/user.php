<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::user.dashboard')->name('dashboard');

// Account
Route::livewire('/profile', 'pages::shared.profile')->name('profile');
Route::livewire('/account-settings', 'pages::user.account.account-settings')->name('account-settings');
Route::livewire('/security-settings', 'pages::user.account.security-settings')->name('security-settings');
Route::livewire('/delete-account', 'pages::user.account.delete-account')->name('delete-account');

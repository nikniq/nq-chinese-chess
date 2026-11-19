<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GameController;
use Illuminate\Support\Facades\Route;

Route::get('/', [GameController::class, 'play'])->name('play');
Route::get('/leaderboard', [GameController::class, 'leaderboard'])->name('leaderboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/games', [GameController::class, 'store'])->middleware('throttle:30,1')->name('games.store');
    Route::get('/history', [GameController::class, 'history'])->name('history');
});

<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/markets/{publicId}', function (string $publicId) {
    return view('market', [
        'publicId' => $publicId,
    ]);
})->name('markets.show');

<?php

use App\Http\Controllers\WrangleWebhookController;
use App\Http\Middleware\VerifyWrangleWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::post(
    '/webhooks/wrangle/market',
    WrangleWebhookController::class
)
    ->middleware(VerifyWrangleWebhookSignature::class)
    ->name('webhooks.wrangle.market');

Route::post(
    '/webhooks/wrangle/callback',
    WrangleWebhookController::class
)
    ->middleware(VerifyWrangleWebhookSignature::class)
    ->name('webhooks.wrangle.callback');

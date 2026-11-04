<?php

declare(strict_types=1);

use Beziworld\Tpay\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

$middleware = Config::get('tpay.notifications.middleware', []);

Route::post((string) Config::get('tpay.notifications.path', 'tpay/notification'), NotificationController::class)
    ->middleware(is_array($middleware) ? $middleware : [])
    ->name((string) Config::get('tpay.notifications.name', 'tpay.notification'));

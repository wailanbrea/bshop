<?php

use App\Http\Controllers\CatalogImageController;
use App\Http\Controllers\SharedOrderController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Middleware\ProtectPublicOrder;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(__DIR__.'/api.php');
Route::get('/s/order/{token}', [SharedOrderController::class, 'show'])->middleware('throttle:order-view-links');
Route::view('/s/{token}', 'app')->where('token', '[a-f0-9]{64}')->middleware(ProtectPublicOrder::class);
Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive']);
Route::get('/media/catalog/{asset}/{rendition}/{hash}.webp', CatalogImageController::class)->withoutMiddleware('web')->whereNumber('asset')->where('rendition', 'thumb|card|detail')->where('hash', '[a-f0-9]{64}');

Route::view('/reset-password/{token}', 'app')->name('password.reset');
Route::view('/login', 'app')->name('login');
Route::view('/join/{token}', 'app')->where('token', '[A-Za-z0-9]{64}');
Route::view('/', 'app');
Route::view('/app/{path?}', 'app')->where('path', '.*');

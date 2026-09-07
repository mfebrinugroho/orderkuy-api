<?php

use App\Http\Controllers\XenditPaymentController;
use App\Http\Controllers\XenditWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
  return response()->json([
    'success' => true,
    'message' => 'Kaserva API is running',
  ]);
});

Route::post('/payment/test', [XenditPaymentController::class, 'test']);
Route::post(
  '/payment/{paymentRequestId}/simulate',
  [XenditPaymentController::class, 'simulate']
);
Route::post('/webhooks/xendit', [XenditWebhookController::class, 'handle']);

require __DIR__ . '/api/customer.php';
require __DIR__ . '/api/staff.php';

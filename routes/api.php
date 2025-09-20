<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\OrderController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);

// Payment webhook (must be public for Midtrans to access)
Route::post('/payment/notification', [PaymentController::class, 'handleNotification']);

// Protected routes
Route::middleware(['auth:sanctum', 'api.key'])->group(function () {
  Route::post('/logout', [AuthController::class, 'logout']);

  // Order routes
  Route::post('/checkout', [OrderController::class, 'checkout']);
  Route::get('/orders', [OrderController::class, 'index']);
  Route::get('/orders/{id}', [OrderController::class, 'show']);

  // Payment routes
  Route::get('/payment/status/{orderId}', [PaymentController::class, 'getPaymentStatus']);
  Route::post('/payment/generate-token/{orderId}', [PaymentController::class, 'generateSnapToken']);
});

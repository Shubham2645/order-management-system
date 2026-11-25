<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('products', ProductController::class);

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/notifications', [OrderController::class, 'notifications']);
    Route::post('/notifications/read-all', [OrderController::class, 'readAllNotifications']);
    Route::post('/notifications/{notification}/read', [OrderController::class, 'readNotification']);

    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/users/assignable', [OrderController::class, 'assignableUsers']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::put('/orders/{order}', [OrderController::class, 'update']);
    Route::delete('/orders/{order}', [OrderController::class, 'destroy']);
    Route::post('/orders/{order}/status', [OrderController::class, 'changeStatus']);
    Route::post('/orders/{order}/assign', [OrderController::class, 'assign']);
    Route::post('/orders/{order}/notes', [OrderController::class, 'addNote']);
});

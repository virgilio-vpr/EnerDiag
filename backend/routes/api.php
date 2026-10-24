<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\ExampleController;
use App\Http\Controllers\Api\V1\StatusController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/status', StatusController::class);
Route::post('/example/validate', [ExampleController::class, 'validateExample']);
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
Route::post('/auth/verify-email', [AuthController::class, 'verifyEmail'])->middleware('throttle:verification_codes');
Route::post('/auth/resend-code', [AuthController::class, 'resendCode'])->middleware('throttle:verification_codes');

Route::get('/user', CurrentUserController::class)->middleware('auth:sanctum');

if (app()->environment('testing', 'local')) {
    Route::post('/test-validation', function (Request $request) {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        return response()->json(['status' => 'ok']);
    });
}

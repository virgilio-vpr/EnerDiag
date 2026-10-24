<?php

use App\Http\Controllers\Api\V1\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Api\V1\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\V1\Admin\SimulationController as AdminSimulationController;
use App\Http\Controllers\Api\V1\Admin\TariffController as AdminTariffController;
use App\Http\Controllers\Api\V1\Admin\TariffImportController as AdminTariffImportController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\ExampleController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\SimulationController;
use App\Http\Controllers\Api\V1\StatusController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/status', StatusController::class);
Route::post('/example/validate', [ExampleController::class, 'validateExample']);
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
Route::post('/auth/verify-email', [AuthController::class, 'verifyEmail'])->middleware('throttle:verification_codes');
Route::post('/auth/resend-code', [AuthController::class, 'resendCode'])->middleware('throttle:verification_codes');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth');
Route::post('/leads', LeadController::class)->middleware('throttle:leads');

Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('/customers', [AdminCustomerController::class, 'index']);
    Route::get('/customers/{id}', [AdminCustomerController::class, 'show'])->whereNumber('id');
    Route::get('/simulations', [AdminSimulationController::class, 'index']);
    Route::get('/simulations/{id}', [AdminSimulationController::class, 'show'])->whereNumber('id');
    Route::post('/simulations/{id}/recalculate', [AdminSimulationController::class, 'recalculate'])->whereNumber('id');
    Route::get('/tariffs', [AdminTariffController::class, 'index']);
    Route::post('/tariffs', [AdminTariffController::class, 'store']);
    Route::put('/tariffs/{id}', [AdminTariffController::class, 'update'])->whereNumber('id');
    Route::post('/tariffs/import', [AdminTariffImportController::class, 'store']);
    Route::put('/reports/{id}', [AdminReportController::class, 'update'])->whereNumber('id');
    Route::post('/reports/{id}/resend', [AdminReportController::class, 'resend'])->whereNumber('id');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/simulations', [SimulationController::class, 'index']);
    Route::post('/simulations', [SimulationController::class, 'store'])->middleware('throttle:uploads');
    Route::get('/simulations/{simulationId}/status', [SimulationController::class, 'status']);
    Route::get('/simulations/{simulationId}/report', [SimulationController::class, 'report']);
    Route::get('/simulations/{simulationId}/pdf', [SimulationController::class, 'pdf']);
    Route::get('/me', [MeController::class, 'show']);
    Route::put('/me', [MeController::class, 'update']);
    Route::delete('/me', [MeController::class, 'destroy']);
    Route::post('/me/contact-request', [MeController::class, 'requestContact']);
});

Route::get('/user', CurrentUserController::class)->middleware('auth:sanctum');

if (app()->environment('testing', 'local')) {
    Route::post('/test-validation', function (Request $request) {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        return response()->json(['status' => 'ok']);
    });
}

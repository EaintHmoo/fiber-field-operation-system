<?php

use App\Http\Controllers\Api\AuthTokenController;
use App\Http\Controllers\Api\InstallationAppointmentController;
use App\Http\Controllers\Api\OnCallAppointmentController;
use App\Http\Controllers\Api\TeamCapacityController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:10,1')->prefix('auth')->name('auth.')->group(function () {
    Route::post('lsp-users/token', [AuthTokenController::class, 'storeLspUser'])->name('lsp-users.token');
    Route::post('operators/token', [AuthTokenController::class, 'storeOperator'])->name('operators.token');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthTokenController::class, 'show'])->name('auth.me');
    Route::delete('auth/token', [AuthTokenController::class, 'destroy'])->name('auth.token.destroy');

    Route::post('installation_requests/{installationRequest}/appointment', [InstallationAppointmentController::class, 'store'])
        ->name('installation-requests.appointment.store');

    Route::post('on_call_requests/{onCallRequest}/appointment', [OnCallAppointmentController::class, 'store'])
        ->name('on-call-requests.appointment.store');

    Route::get('lsp-teams/{lspTeam}/capacity', [TeamCapacityController::class, 'show'])
        ->name('lsp-teams.capacity.show');
});

<?php

use App\Http\Controllers\FormConfigController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('api')->group(function () {
    Route::get('/form-config/{formName}', [FormConfigController::class, 'show']);
    Route::put('/form-config/{formName}/system', [FormConfigController::class, 'updateSystem']);
    Route::put('/form-config/{formName}/user', [FormConfigController::class, 'updateUser']);
    Route::delete('/form-config/{formName}/user', [FormConfigController::class, 'resetUser']);

    Route::get('/filters/{filterableType}', [FormConfigController::class, 'filters']);
    Route::post('/filters', [FormConfigController::class, 'storeFilter']);
    Route::put('/filters/{filter}', [FormConfigController::class, 'updateFilter']);
    Route::delete('/filters/{filter}', [FormConfigController::class, 'destroyFilter']);
});

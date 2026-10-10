<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);
    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
    Route::get('/membership-requests', [OrganizationController::class, 'myRequests']);
    Route::get('/my-organizations', [OrganizationController::class, 'myOrganizations']);
    Route::get('/officer/requests', [OrganizationController::class, 'officerRequests']);
    Route::patch('/officer/requests/{membershipRequest}', [OrganizationController::class, 'decideRequest']);
    Route::post('/organizations/{organization}/membership-requests', [OrganizationController::class, 'requestToJoin']);
    Route::delete('/organizations/{organization}/membership-requests', [OrganizationController::class, 'withdraw']);
    Route::get('/organizations/{organization}/members', [OrganizationController::class, 'members']);
});

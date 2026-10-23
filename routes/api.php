<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);
    Route::patch('/profile', [UserController::class, 'updateProfile']);
    Route::delete('/profile-picture', [UserController::class, 'removeProfilePicture']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::patch('/users/{user}', [UserController::class, 'update']);

    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
    Route::get('/membership-requests', [OrganizationController::class, 'myRequests']);
    Route::get('/my-organizations', [OrganizationController::class, 'myOrganizations']);
    Route::get('/officer/requests', [OrganizationController::class, 'officerRequests']);
    Route::patch('/officer/requests/{membershipRequest}', [OrganizationController::class, 'decideRequest']);
    Route::delete('/officer/organizations/{organization}/members/{member}', [OrganizationController::class, 'removeMember']);
    Route::patch('/officer/organizations/{organization}/members/{member}', [OrganizationController::class, 'promoteMember']);
    Route::get('/announcements', [OrganizationController::class, 'announcements']);
    Route::get('/my-events', [OrganizationController::class, 'myEvents']);
    Route::post('/organizations/{organization}/announcements', [OrganizationController::class, 'postAnnouncement']);
    Route::delete('/organizations/{organization}/announcements/{announcement}', [OrganizationController::class, 'deleteAnnouncement']);
    Route::delete('/organizations/{organization}/events/{event}', [OrganizationController::class, 'deleteEvent']);
    Route::post('/organizations/{organization}/events/{event}/rsvp', [OrganizationController::class, 'rsvpToEvent']);
    Route::delete('/organizations/{organization}/events/{event}/rsvp', [OrganizationController::class, 'cancelEventRsvp']);
    Route::get('/officer/organizations/{organization}/events', [OrganizationController::class, 'officerEvents']);
    Route::get('/officer/events/{event}/attendance', [OrganizationController::class, 'eventAttendance']);
    Route::post('/officer/events/{event}/attendance/{user}', [OrganizationController::class, 'markEventAttendance']);
    Route::post('/organizations/{organization}/membership-requests', [OrganizationController::class, 'requestToJoin']);
    Route::delete('/organizations/{organization}/membership-requests', [OrganizationController::class, 'withdraw']);
    Route::get('/organizations/{organization}/members', [OrganizationController::class, 'members']);
});

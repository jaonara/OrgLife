<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DuesController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OrganizationProposalController;
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
    Route::get('/my-organization-proposals', [OrganizationProposalController::class, 'mine']);
    Route::post('/organization-proposals', [OrganizationProposalController::class, 'createDraft']);
    Route::put('/organization-proposals/{proposal}', [OrganizationProposalController::class, 'updateDraft']);
    Route::patch('/organization-proposals/{proposal}/submit', [OrganizationProposalController::class, 'submit']);
    Route::get('/admin/organization-proposals', [OrganizationProposalController::class, 'adminIndex']);
    Route::patch('/admin/organization-proposals/{proposal}', [OrganizationProposalController::class, 'decide']);
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
    Route::get('/membership-requests', [OrganizationController::class, 'myRequests']);
    Route::get('/my-organizations', [OrganizationController::class, 'myOrganizations']);
    Route::get('/my-dues', [DuesController::class, 'mine']);
    Route::post('/my-dues/{organizationDue}/payments', [DuesController::class, 'submitMemberPayment']);
    Route::patch('/my-dues/{organizationDue}/payments/{payment}', [DuesController::class, 'updateMemberPayment']);
    Route::get('/officer/pending-dues-payments', [DuesController::class, 'pendingReviewCount']);
    Route::get('/officer/organizations/{organization}/dues', [DuesController::class, 'manage']);
    Route::post('/officer/organizations/{organization}/dues', [DuesController::class, 'save']);
    Route::post('/officer/organizations/{organization}/gcash', [DuesController::class, 'saveGcashDetails']);
    Route::post('/officer/organizations/{organization}/dues/{organizationDue}/payments', [DuesController::class, 'recordPayment']);
    Route::patch('/officer/organizations/{organization}/dues/{organizationDue}/payments/{payment}', [DuesController::class, 'updatePayment']);
    Route::patch('/officer/organizations/{organization}/dues/{organizationDue}/payments/{payment}/review', [DuesController::class, 'reviewPayment']);
    Route::get('/officer/organizations/{organization}/dues/{organizationDue}/payments/{payment}/proof', [DuesController::class, 'paymentProof']);
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
    Route::post('/organizations/{organization}/events', [OrganizationController::class, 'createEvent']);
    Route::patch('/organizations/{organization}/events/{event}', [OrganizationController::class, 'updateEvent']);
    Route::patch('/organizations/{organization}/events/{event}/cancel', [OrganizationController::class, 'cancelEvent']);
    Route::get('/officer/organizations/{organization}/events', [OrganizationController::class, 'officerEvents']);
    Route::get('/officer/events/{event}/attendance', [OrganizationController::class, 'eventAttendance']);
    Route::post('/officer/events/{event}/attendance/{user}', [OrganizationController::class, 'markEventAttendance']);
    Route::post('/organizations/{organization}/membership-requests', [OrganizationController::class, 'requestToJoin']);
    Route::delete('/organizations/{organization}/membership-requests', [OrganizationController::class, 'withdraw']);
    Route::get('/organizations/{organization}/members', [OrganizationController::class, 'members']);
});

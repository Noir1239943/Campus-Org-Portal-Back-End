<?php

use App\Http\Controllers\Api\Adviser;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\Officer;
use App\Http\Controllers\Api\Student;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::get('meta', [AuthController::class, 'meta']);

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
});

/*
|--------------------------------------------------------------------------
| Signed in (any role)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
        Route::put('password', [AuthController::class, 'changePassword']);
    });

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);

    /*
    |----------------------------------------------------------------------
    | Student workspace
    |----------------------------------------------------------------------
    */
    Route::middleware('student')->group(function () {
        Route::get('dashboard', Student\DashboardController::class);

        Route::get('organizations', [Student\OrganizationController::class, 'index']);
        Route::get('organizations/{organization:slug}', [Student\OrganizationController::class, 'show']);
        Route::post('organizations/{organization:slug}/save', [Student\OrganizationController::class, 'toggleSave']);
        Route::post('organizations/{organization:slug}/applications', [Student\ApplicationController::class, 'store']);
        Route::get('my/organizations', [Student\OrganizationController::class, 'mine']);

        Route::get('applications', [Student\ApplicationController::class, 'index']);
        Route::get('applications/{application}', [Student\ApplicationController::class, 'show']);
        Route::post('applications/{application}/withdraw', [Student\ApplicationController::class, 'withdraw']);

        Route::get('proposals', [Student\ProposalController::class, 'index']);
        Route::post('proposals', [Student\ProposalController::class, 'store']);
        Route::post('proposals/{proposal}/withdraw', [Student\ProposalController::class, 'withdraw']);

        Route::get('events', [Student\EventController::class, 'index']);
        Route::get('events/{event}', [Student\EventController::class, 'show']);
        Route::post('events/{event}/register', [Student\EventController::class, 'register']);
        Route::delete('events/{event}/register', [Student\EventController::class, 'cancelRegistration']);
        Route::post('events/{event}/excuse', [Student\EventController::class, 'excuse']);
        Route::get('excuses', [Student\EventController::class, 'excuses']);

        Route::post('check-in', [Student\CheckInController::class, 'store'])->middleware('throttle:check-in');
        Route::get('clearance', Student\ClearanceController::class);
        Route::get('announcements', [AnnouncementController::class, 'index']);
    });

    /*
    |----------------------------------------------------------------------
    | Officer workspace (acts on the organization in X-Organization-Id)
    |----------------------------------------------------------------------
    */
    Route::middleware('officer')->prefix('officer')->group(function () {
        Route::get('overview', Officer\OverviewController::class);

        Route::get('applications', [Officer\ApplicationController::class, 'index']);
        Route::post('applications/{application}/approve', [Officer\ApplicationController::class, 'approve']);
        Route::post('applications/{application}/reject', [Officer\ApplicationController::class, 'reject']);

        Route::get('members', [Officer\MemberController::class, 'index']);
        Route::post('members', [Officer\MemberController::class, 'store']);
        Route::get('members/export', [Officer\MemberController::class, 'export']);
        Route::patch('members/{membership}', [Officer\MemberController::class, 'update']);
        Route::delete('members/{membership}', [Officer\MemberController::class, 'destroy']);

        Route::get('events', [Officer\EventController::class, 'index']);
        Route::post('events', [Officer\EventController::class, 'store']);
        Route::get('events/{event}', [Officer\EventController::class, 'show']);
        Route::put('events/{event}', [Officer\EventController::class, 'update']);
        Route::delete('events/{event}', [Officer\EventController::class, 'destroy']);
        Route::post('events/{event}/submit', [Officer\EventController::class, 'submit']);
        Route::post('events/{event}/cancel', [Officer\EventController::class, 'cancel']);

        Route::get('events/{event}/attendance', [Officer\AttendanceController::class, 'show']);
        Route::get('events/{event}/attendance/export', [Officer\AttendanceController::class, 'export']);
        Route::post('events/{event}/attendance', [Officer\AttendanceController::class, 'mark']);
        Route::post('events/{event}/check-in/open', [Officer\AttendanceController::class, 'open']);
        Route::post('events/{event}/check-in/close', [Officer\AttendanceController::class, 'close']);
        Route::get('events/{event}/check-in/code', [Officer\AttendanceController::class, 'code']);

        Route::get('excuses', [Officer\ExcuseController::class, 'index']);
        Route::post('excuses/{excuse}/approve', [Officer\ExcuseController::class, 'approve']);
        Route::post('excuses/{excuse}/reject', [Officer\ExcuseController::class, 'reject']);

        Route::get('announcements', [Officer\AnnouncementController::class, 'index']);
        Route::post('announcements', [Officer\AnnouncementController::class, 'store']);
        Route::put('announcements/{announcement}', [Officer\AnnouncementController::class, 'update']);
        Route::delete('announcements/{announcement}', [Officer\AnnouncementController::class, 'destroy']);

        Route::get('organization', [Officer\OrganizationController::class, 'show']);
        Route::put('organization', [Officer\OrganizationController::class, 'update']);
    });

    /*
    |----------------------------------------------------------------------
    | Faculty adviser / Office of Student Affairs workspace
    |----------------------------------------------------------------------
    */
    Route::middleware('adviser')->prefix('adviser')->group(function () {
        Route::get('overview', Adviser\OverviewController::class);

        Route::get('organizations', [Adviser\OrganizationController::class, 'index']);
        Route::post('organizations', [Adviser\OrganizationController::class, 'store']);
        Route::get('organizations/{organization}', [Adviser\OrganizationController::class, 'show']);
        Route::put('organizations/{organization}', [Adviser\OrganizationController::class, 'update']);
        Route::delete('organizations/{organization}', [Adviser\OrganizationController::class, 'destroy']);
        Route::patch('organizations/{organization}/status', [Adviser\OrganizationController::class, 'updateStatus']);
        Route::post('organizations/{organization}/officers', [Adviser\OrganizationController::class, 'addOfficer']);
        Route::patch('organizations/{organization}/officers/{membership}', [Adviser\OrganizationController::class, 'updateOfficer']);

        Route::get('proposals', [Adviser\ProposalController::class, 'index']);
        Route::post('proposals/{proposal}/approve', [Adviser\ProposalController::class, 'approve']);
        Route::post('proposals/{proposal}/reject', [Adviser\ProposalController::class, 'reject']);

        Route::get('events', [Adviser\EventController::class, 'index']);
        Route::post('events/{event}/approve', [Adviser\EventController::class, 'approve']);
        Route::post('events/{event}/reject', [Adviser\EventController::class, 'reject']);

        Route::get('attendance', Adviser\AttendanceController::class);

        Route::prefix('accounts')->group(function () {
            Route::get('/', [Adviser\AccountController::class, 'index']);
            Route::post('/', [Adviser\AccountController::class, 'store']);
            Route::post('import', [Adviser\AccountController::class, 'import']);
            Route::put('{user}', [Adviser\AccountController::class, 'update']);
            Route::post('{user}/reset-password', [Adviser\AccountController::class, 'resetPassword']);
            Route::post('{user}/disable', [Adviser\AccountController::class, 'disable']);
            Route::post('{user}/enable', [Adviser\AccountController::class, 'enable']);
        });

        Route::get('students', [Adviser\StudentController::class, 'index']);
        Route::get('students/{user}', [Adviser\StudentController::class, 'show']);
        Route::post('students/{user}/reset-password', [Adviser\StudentController::class, 'resetPassword']);

        Route::get('reports/organizations', [Adviser\ReportController::class, 'organizations']);
        Route::get('reports/attendance', [Adviser\ReportController::class, 'attendance']);
        Route::get('reports/memberships', [Adviser\ReportController::class, 'memberships']);
        Route::get('reports/participation', [Adviser\ReportController::class, 'participation']);

        Route::get('announcements', [Adviser\AnnouncementController::class, 'index']);
        Route::post('announcements', [Adviser\AnnouncementController::class, 'store']);
        Route::put('announcements/{announcement}', [Adviser\AnnouncementController::class, 'update']);
        Route::delete('announcements/{announcement}', [Adviser\AnnouncementController::class, 'destroy']);
    });
});

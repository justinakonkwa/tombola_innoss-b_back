<?php

use App\Http\Controllers\Api\V1\Admin\AuditController;
use App\Http\Controllers\Api\V1\Admin\CampaignController as AdminCampaignController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DrawController;
use App\Http\Controllers\Api\V1\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Api\V1\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\V1\Admin\PrizeController;
use App\Http\Controllers\Api\V1\Admin\RefundController;
use App\Http\Controllers\Api\V1\Admin\RiskController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SecurityController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\TicketController as AdminTicketController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\Admin\WinnerController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PublicController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — Tombola Innoss’B
|--------------------------------------------------------------------------
|
| API versionnée (cahier des charges §30). Toutes les routes sont limitées en
| fréquence ; les routes sensibles cumulent authentification, RBAC et MFA.
|
*/

Route::prefix('v1')->group(function () {

    // ------------------------------------------------------------------ public
    Route::get('/health', [PublicController::class, 'health']);
    Route::get('/settings/public', [PublicController::class, 'publicSettings']);
    Route::get('/campaigns', [PublicController::class, 'campaigns']);
    Route::get('/campaigns/{slug}', [PublicController::class, 'campaign']);
    Route::get('/campaigns/{slug}/stats', [PublicController::class, 'campaignStats']);
    Route::get('/winners', [PublicController::class, 'winners']);
    Route::get('/faq', [PublicController::class, 'faq']);
    Route::get('/draws/{reference}/verify', [PublicController::class, 'verifyDraw']);

    // ------------------------------------------------------------ authentification
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:5,1');
        Route::post('/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1');
        Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:30,1');

        // Seule route ouverte à un jeton de défi MFA : la résolution du défi.
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/mfa/verify', [AuthController::class, 'verifyMfaChallenge'])->middleware('throttle:10,1');
        });

        // Toutes les autres routes authentifiées refusent un jeton de défi :
        // une authentification partielle ne donne accès à aucune action métier.
        Route::middleware(['auth:sanctum', 'challenge.guard'])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/logout-all', [AuthController::class, 'logoutAll']);
            Route::post('/mfa/enroll', [AuthController::class, 'enrollMfa']);
            Route::post('/mfa/confirm', [AuthController::class, 'confirmMfa']);
            Route::post('/mfa/disable', [AuthController::class, 'disableMfa']);
        });
    });

    // ------------------------------------------------------------- webhooks
    // Pas d'authentification par jeton : la requête est authentifiée par la
    // signature HMAC de la passerelle, vérifiée dans le contrôleur.
    Route::post('/webhooks/futaye', [WebhookController::class, 'futaye'])
        ->middleware('throttle:120,1')
        ->name('webhooks.futaye');

    // ------------------------------------------------------- espace participant
    Route::middleware(['auth:sanctum', 'challenge.guard'])->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::patch('/me', [MeController::class, 'update']);
        Route::post('/me/password', [MeController::class, 'updatePassword'])->middleware('throttle:6,1');
        Route::get('/me/dashboard', [MeController::class, 'dashboard']);
        Route::get('/me/tickets', [MeController::class, 'tickets']);
        Route::get('/me/orders', [MeController::class, 'orders']);
        Route::get('/me/payments', [MeController::class, 'payments']);
        Route::get('/me/notifications', [MeController::class, 'notifications']);
        Route::get('/me/sessions', [MeController::class, 'sessions']);
        Route::delete('/me/sessions/{session}', [MeController::class, 'revokeSession']);

        // Commandes & paiements
        Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/orders/{reference}', [OrderController::class, 'show']);
        Route::post('/orders/{reference}/cancel', [OrderController::class, 'cancel']);
        Route::post('/orders/{reference}/pay', [OrderController::class, 'pay'])->middleware('throttle:20,1');
        Route::get('/payments/{payment}/status', [PaymentController::class, 'status']);

        // Tickets
        Route::get('/tickets/{ticketNumber}', [TicketController::class, 'show']);
        Route::post('/tickets/verify', [TicketController::class, 'verify'])->middleware(['staff', 'permission:tickets.view']);
    });

    // ------------------------------------------------------------- back-office
    Route::prefix('admin')
        ->middleware(['auth:sanctum', 'challenge.guard', 'staff', 'mfa'])
        ->group(function () {

            Route::get('/dashboard', [DashboardController::class, 'index'])
                ->middleware('permission:campaigns.view,payments.view');

            // Campagnes
            Route::get('/campaigns', [AdminCampaignController::class, 'index'])->middleware('permission:campaigns.view');
            Route::post('/campaigns', [AdminCampaignController::class, 'store'])->middleware('permission:campaigns.create');
            Route::get('/campaigns/{campaign}', [AdminCampaignController::class, 'show'])->middleware('permission:campaigns.view');
            Route::patch('/campaigns/{campaign}', [AdminCampaignController::class, 'update'])->middleware('permission:campaigns.update');
            Route::delete('/campaigns/{campaign}', [AdminCampaignController::class, 'destroy'])->middleware('permission:campaigns.delete');
            Route::post('/campaigns/{campaign}/status', [AdminCampaignController::class, 'changeStatus'])->middleware('permission:campaigns.update');

            // Lots
            Route::get('/prizes', [PrizeController::class, 'index'])->middleware('permission:campaigns.view');
            Route::post('/prizes', [PrizeController::class, 'store'])->middleware('permission:prizes.manage');
            Route::get('/prizes/{prize}', [PrizeController::class, 'show'])->middleware('permission:campaigns.view');
            Route::patch('/prizes/{prize}', [PrizeController::class, 'update'])->middleware('permission:prizes.manage');
            Route::delete('/prizes/{prize}', [PrizeController::class, 'destroy'])->middleware('permission:prizes.manage');

            // Tickets
            Route::get('/tickets', [AdminTicketController::class, 'index'])->middleware('permission:tickets.view');
            Route::get('/tickets/{ticket}', [AdminTicketController::class, 'show'])->middleware('permission:tickets.view');
            Route::post('/tickets/{ticket}/cancel', [AdminTicketController::class, 'cancel'])->middleware('permission:tickets.cancel');
            Route::post('/ticket-batches/{batch}/cancel', [AdminTicketController::class, 'cancelBatch'])->middleware('permission:tickets.cancel');

            // Participants
            Route::get('/users', [AdminUserController::class, 'index'])->middleware('permission:users.view');
            Route::get('/users/{user}', [AdminUserController::class, 'show'])->middleware('permission:users.view');
            Route::post('/users/{user}/status', [AdminUserController::class, 'changeStatus'])->middleware('permission:users.manage');
            Route::post('/users/{user}/roles', [AdminUserController::class, 'assignRoles'])->middleware('permission:roles.manage');

            // Paiements
            Route::get('/payments', [AdminPaymentController::class, 'index'])->middleware('permission:payments.view');
            Route::get('/payments/summary', [AdminPaymentController::class, 'summary'])->middleware('permission:payments.view');
            Route::get('/payments/{payment}', [AdminPaymentController::class, 'show'])->middleware('permission:payments.view');
            Route::post('/payments/{payment}/reconcile', [AdminPaymentController::class, 'reconcile'])->middleware('permission:payments.reconcile');
            Route::get('/payment-webhooks', [AdminPaymentController::class, 'webhooks'])->middleware('permission:payments.view');

            // Remboursements
            Route::get('/refunds', [RefundController::class, 'index'])->middleware('permission:payments.view');
            Route::post('/refunds', [RefundController::class, 'store'])->middleware('permission:refunds.request');
            Route::post('/refunds/{refund}/approve', [RefundController::class, 'approve'])->middleware('permission:refunds.approve');
            Route::post('/refunds/{refund}/reject', [RefundController::class, 'reject'])->middleware('permission:refunds.approve');

            // Tirages
            Route::get('/draws', [DrawController::class, 'index'])->middleware('permission:draws.view');
            Route::post('/draws', [DrawController::class, 'store'])->middleware('permission:draws.execute');
            Route::get('/draws/{draw}', [DrawController::class, 'show'])->middleware('permission:draws.view');
            Route::post('/draws/{draw}/close', [DrawController::class, 'close'])->middleware('permission:draws.execute');
            Route::post('/draws/{draw}/snapshot', [DrawController::class, 'snapshot'])->middleware('permission:draws.execute');
            Route::post('/draws/{draw}/execute', [DrawController::class, 'execute'])->middleware('permission:draws.execute');
            Route::post('/draws/{draw}/publish', [DrawController::class, 'publish'])->middleware('permission:draws.publish');
            Route::get('/draws/{draw}/verify', [DrawController::class, 'verify'])->middleware('permission:draws.view');

            // Gagnants
            Route::get('/winners', [WinnerController::class, 'index'])->middleware('permission:winners.manage');
            Route::get('/winners/{winner}', [WinnerController::class, 'show'])->middleware('permission:winners.manage');
            Route::patch('/winners/{winner}', [WinnerController::class, 'update'])->middleware('permission:winners.manage');
            Route::post('/winners/{winner}/status', [WinnerController::class, 'changeStatus'])->middleware('permission:winners.manage');

            // Notifications
            Route::get('/notifications', [AdminNotificationController::class, 'index'])->middleware('permission:notifications.send');
            Route::post('/notifications/broadcast', [AdminNotificationController::class, 'broadcast'])->middleware('permission:notifications.send');

            // Journal d'audit & sécurité
            Route::get('/audit-logs', [AuditController::class, 'index'])->middleware('permission:audit.view');
            Route::get('/audit-logs/verify', [AuditController::class, 'verify'])->middleware('permission:audit.view');
            Route::get('/security-events', [SecurityController::class, 'index'])->middleware('permission:security.view');
            Route::post('/security-events/{event}/resolve', [SecurityController::class, 'resolve'])->middleware('permission:security.manage');

            // Anti-fraude
            Route::get('/risk-assessments', [RiskController::class, 'index'])->middleware('permission:risk.review');
            Route::post('/risk-assessments/{assessment}/review', [RiskController::class, 'review'])->middleware('permission:risk.review');

            // Rôles & paramètres
            Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.manage');
            Route::post('/roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->middleware('permission:roles.manage');
            Route::get('/settings', [SettingController::class, 'index'])->middleware('permission:settings.manage');
            Route::put('/settings', [SettingController::class, 'update'])->middleware('permission:settings.manage');
        });
});

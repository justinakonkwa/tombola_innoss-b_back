<?php

namespace App\Enums;

enum AuditAction: string
{
    case UserRegistered = 'USER_REGISTERED';
    case UserLoggedIn = 'USER_LOGIN';
    case UserLoginFailed = 'USER_LOGIN_FAILED';
    case UserLoggedOut = 'USER_LOGOUT';
    case OrderCreated = 'ORDER_CREATED';
    case PaymentInitiated = 'PAYMENT_INITIATED';
    case PaymentConfirmed = 'PAYMENT_CONFIRMED';
    case PaymentFailed = 'PAYMENT_FAILED';
    case WebhookRejected = 'WEBHOOK_REJECTED';
    case TicketsIssued = 'TICKETS_ISSUED';
    case TicketCancelled = 'TICKET_CANCELLED';
    case CampaignCreated = 'CAMPAIGN_CREATED';
    case CampaignUpdated = 'CAMPAIGN_UPDATED';
    case CampaignStatusChanged = 'CAMPAIGN_STATUS_CHANGED';
    case PrizeCreated = 'PRIZE_CREATED';
    case PrizeUpdated = 'PRIZE_UPDATED';
    case DrawCreated = 'DRAW_CREATED';
    case DrawClosed = 'DRAW_CLOSED';
    case DrawSnapshotCreated = 'DRAW_SNAPSHOT_CREATED';
    case DrawExecuted = 'DRAW_EXECUTED';
    case DrawPublished = 'DRAW_PUBLISHED';
    case WinnerValidated = 'WINNER_VALIDATED';
    case WinnerDelivered = 'WINNER_DELIVERED';
    case RefundRequested = 'REFUND_REQUESTED';
    case RefundApproved = 'REFUND_APPROVED';
    case RefundCompleted = 'REFUND_COMPLETED';
    case UserSuspended = 'USER_SUSPENDED';
    case RoleAssigned = 'ROLE_ASSIGNED';
    case SettingsUpdated = 'SETTINGS_UPDATED';
    case RiskReviewed = 'RISK_REVIEWED';
}

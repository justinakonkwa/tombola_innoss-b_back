<?php

namespace App\Enums;

enum PermissionName: string
{
    // Campagnes & lots
    case CampaignsView = 'campaigns.view';
    case CampaignsCreate = 'campaigns.create';
    case CampaignsUpdate = 'campaigns.update';
    case CampaignsDelete = 'campaigns.delete';
    case PrizesManage = 'prizes.manage';

    // Tickets & participants
    case TicketsView = 'tickets.view';
    case TicketsCancel = 'tickets.cancel';
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';

    // Finance
    case PaymentsView = 'payments.view';
    case PaymentsReconcile = 'payments.reconcile';
    case RefundsRequest = 'refunds.request';
    case RefundsApprove = 'refunds.approve';
    case FinanceReports = 'finance.reports';

    // Tirages
    case DrawsView = 'draws.view';
    case DrawsExecute = 'draws.execute';
    case DrawsPublish = 'draws.publish';
    case WinnersManage = 'winners.manage';

    // Exploitation
    case NotificationsSend = 'notifications.send';
    case AuditView = 'audit.view';
    case SecurityView = 'security.view';
    case SecurityManage = 'security.manage';
    case SettingsManage = 'settings.manage';
    case RolesManage = 'roles.manage';
    case RiskReview = 'risk.review';

    public function group(): string
    {
        return explode('.', $this->value)[0];
    }

    public function label(): string
    {
        return match ($this) {
            self::CampaignsView => 'Consulter les campagnes',
            self::CampaignsCreate => 'Créer une campagne',
            self::CampaignsUpdate => 'Modifier une campagne',
            self::CampaignsDelete => 'Supprimer une campagne',
            self::PrizesManage => 'Gérer les lots',
            self::TicketsView => 'Consulter les tickets',
            self::TicketsCancel => 'Annuler un ticket',
            self::UsersView => 'Consulter les participants',
            self::UsersManage => 'Gérer les participants',
            self::PaymentsView => 'Consulter les paiements',
            self::PaymentsReconcile => 'Réconcilier les paiements',
            self::RefundsRequest => 'Demander un remboursement',
            self::RefundsApprove => 'Approuver un remboursement',
            self::FinanceReports => 'Accéder aux rapports financiers',
            self::DrawsView => 'Consulter les tirages',
            self::DrawsExecute => 'Exécuter un tirage',
            self::DrawsPublish => 'Publier un tirage',
            self::WinnersManage => 'Gérer les gagnants',
            self::NotificationsSend => 'Envoyer des notifications',
            self::AuditView => 'Consulter le journal d’audit',
            self::SecurityView => 'Consulter les événements de sécurité',
            self::SecurityManage => 'Gérer la sécurité',
            self::SettingsManage => 'Gérer les paramètres',
            self::RolesManage => 'Gérer les rôles',
            self::RiskReview => 'Traiter les alertes anti-fraude',
        };
    }
}

<?php

namespace App\Enums;

enum AccessPermission: string
{
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDelete = 'users.delete';
    case RolesView = 'roles.view';
    case RolesCreate = 'roles.create';
    case RolesUpdate = 'roles.update';
    case RolesDelete = 'roles.delete';
    case InvitationsView = 'invitations.view';
    case InvitationsCreate = 'invitations.create';
    case InvitationsUpdate = 'invitations.update';
    case InvitationsDelete = 'invitations.delete';
    case InvestorsView = 'investors.view';
    case InvestorsCreate = 'investors.create';
    case InvestorsUpdate = 'investors.update';
    case InvestorsDelete = 'investors.delete';
    case EmissionsView = 'emissions.view';
    case EmissionsCreate = 'emissions.create';
    case EmissionsUpdate = 'emissions.update';
    case EmissionsDelete = 'emissions.delete';
    case ExpensesView = 'expenses.view';
    case ExpensesCreate = 'expenses.create';
    case ExpensesUpdate = 'expenses.update';
    case ExpensesDelete = 'expenses.delete';
    case FundsView = 'funds.view';
    case FundsCreate = 'funds.create';
    case FundsUpdate = 'funds.update';
    case FundsDelete = 'funds.delete';
    case DocumentsView = 'documents.view';
    case DocumentsCreate = 'documents.create';
    case DocumentsUpdate = 'documents.update';
    case DocumentsDelete = 'documents.delete';
    case ProposalsView = 'proposals.view';
    case ProposalsUpdate = 'proposals.update';
    case ProposalRepresentativesView = 'proposal-representatives.view';
    case ProposalRepresentativesCreate = 'proposal-representatives.create';
    case ProposalRepresentativesUpdate = 'proposal-representatives.update';
    case ProposalRepresentativesDelete = 'proposal-representatives.delete';
    case ConstructionsView = 'constructions.view';
    case ConstructionsCreate = 'constructions.create';
    case ConstructionsUpdate = 'constructions.update';
    case ConstructionsDelete = 'constructions.delete';
    case SalesBoardsView = 'sales-boards.view';
    case SalesBoardsCreate = 'sales-boards.create';
    case SalesBoardsUpdate = 'sales-boards.update';
    case SalesBoardsDelete = 'sales-boards.delete';
    case ReceivablesView = 'receivables.view';
    case ReceivablesCreate = 'receivables.create';
    case ReceivablesUpdate = 'receivables.update';
    case ReceivablesDelete = 'receivables.delete';
    case RecruitmentVacanciesView = 'recruitment.vacancies.view';
    case RecruitmentVacanciesCreate = 'recruitment.vacancies.create';
    case RecruitmentVacanciesUpdate = 'recruitment.vacancies.update';
    case RecruitmentVacanciesDelete = 'recruitment.vacancies.delete';
    case RecruitmentApplicationsView = 'recruitment.applications.view';
    case RecruitmentApplicationsUpdate = 'recruitment.applications.update';
    case RecruitmentApplicationsDelete = 'recruitment.applications.delete';
    case NimbusSubmissionsView = 'nimbus.submissions.view';
    case NimbusSubmissionsUpdate = 'nimbus.submissions.update';
    case NimbusSubmissionsDelete = 'nimbus.submissions.delete';
    case NimbusPortalUsersView = 'nimbus.portal-users.view';
    case NimbusPortalUsersCreate = 'nimbus.portal-users.create';
    case NimbusPortalUsersUpdate = 'nimbus.portal-users.update';
    case NimbusPortalUsersDelete = 'nimbus.portal-users.delete';
    case NimbusAccessTokensView = 'nimbus.access-tokens.view';
    case NimbusAccessTokensCreate = 'nimbus.access-tokens.create';
    case NimbusAccessTokensUpdate = 'nimbus.access-tokens.update';
    case NimbusAccessTokensDelete = 'nimbus.access-tokens.delete';
    case NimbusDocumentCategoriesView = 'nimbus.document-categories.view';
    case NimbusDocumentCategoriesCreate = 'nimbus.document-categories.create';
    case NimbusDocumentCategoriesUpdate = 'nimbus.document-categories.update';
    case NimbusDocumentCategoriesDelete = 'nimbus.document-categories.delete';
    case NimbusGeneralDocumentsView = 'nimbus.general-documents.view';
    case NimbusGeneralDocumentsCreate = 'nimbus.general-documents.create';
    case NimbusGeneralDocumentsUpdate = 'nimbus.general-documents.update';
    case NimbusGeneralDocumentsDelete = 'nimbus.general-documents.delete';
    case NimbusPortalDocumentsView = 'nimbus.portal-documents.view';
    case NimbusPortalDocumentsCreate = 'nimbus.portal-documents.create';
    case NimbusPortalDocumentsUpdate = 'nimbus.portal-documents.update';
    case NimbusPortalDocumentsDelete = 'nimbus.portal-documents.delete';
    case NimbusAnnouncementsView = 'nimbus.announcements.view';
    case NimbusAnnouncementsCreate = 'nimbus.announcements.create';
    case NimbusAnnouncementsUpdate = 'nimbus.announcements.update';
    case NimbusAnnouncementsDelete = 'nimbus.announcements.delete';
    case NimbusNotificationOutboxesView = 'nimbus.notification-outboxes.view';
    case NimbusNotificationSettingsView = 'nimbus.notification-settings.view';
    case NimbusNotificationSettingsUpdate = 'nimbus.notification-settings.update';
    case AuditActivitiesView = 'audit.activities.view';
    case AuditDocumentDownloadsView = 'audit.document-downloads.view';
    case ReportsView = 'reports.view';
    case SettingsView = 'settings.view';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $permission): string => $permission->value,
            self::cases(),
        );
    }

    /**
     * @return list<string>
     */
    public static function panelEntryValues(): array
    {
        return array_values(array_filter(
            self::values(),
            static fn (string $permission): bool => str_ends_with($permission, '.view'),
        ));
    }

    public static function labelFor(string $permission): string
    {
        return self::tryFrom($permission)?->label() ?? $permission;
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'super-admin' => 'Super Admin',
            'admin' => 'Administrador',
            'editor' => 'Editor',
            'commercial-representative' => 'Representante comercial',
            default => str($role)->replace(['-', '_'], ' ')->title()->toString(),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::UsersView => 'Usuários: visualizar',
            self::UsersCreate => 'Usuários: criar',
            self::UsersUpdate => 'Usuários: editar',
            self::UsersDelete => 'Usuários: excluir',
            self::RolesView => 'Perfis: visualizar',
            self::RolesCreate => 'Perfis: criar',
            self::RolesUpdate => 'Perfis: editar',
            self::RolesDelete => 'Perfis: excluir',
            self::InvitationsView => 'Convites: visualizar',
            self::InvitationsCreate => 'Convites: criar',
            self::InvitationsUpdate => 'Convites: editar',
            self::InvitationsDelete => 'Convites: excluir',
            self::InvestorsView => 'Investidores: visualizar',
            self::InvestorsCreate => 'Investidores: criar',
            self::InvestorsUpdate => 'Investidores: editar',
            self::InvestorsDelete => 'Investidores: excluir',
            self::EmissionsView => 'Emissões: visualizar',
            self::EmissionsCreate => 'Emissões: criar',
            self::EmissionsUpdate => 'Emissões: editar',
            self::EmissionsDelete => 'Emissões: excluir',
            self::ExpensesView => 'Despesas: visualizar',
            self::ExpensesCreate => 'Despesas: criar',
            self::ExpensesUpdate => 'Despesas: editar',
            self::ExpensesDelete => 'Despesas: excluir',
            self::FundsView => 'Fundos: visualizar',
            self::FundsCreate => 'Fundos: criar',
            self::FundsUpdate => 'Fundos: editar',
            self::FundsDelete => 'Fundos: excluir',
            self::DocumentsView => 'Documentos: visualizar',
            self::DocumentsCreate => 'Documentos: criar',
            self::DocumentsUpdate => 'Documentos: editar',
            self::DocumentsDelete => 'Documentos: excluir',
            self::ProposalsView => 'Propostas: visualizar',
            self::ProposalsUpdate => 'Propostas: editar andamento',
            self::ProposalRepresentativesView => 'Representantes comerciais: visualizar',
            self::ProposalRepresentativesCreate => 'Representantes comerciais: criar',
            self::ProposalRepresentativesUpdate => 'Representantes comerciais: editar',
            self::ProposalRepresentativesDelete => 'Representantes comerciais: excluir',
            self::ConstructionsView => 'Obras: visualizar',
            self::ConstructionsCreate => 'Obras: criar',
            self::ConstructionsUpdate => 'Obras: editar',
            self::ConstructionsDelete => 'Obras: excluir',
            self::SalesBoardsView => 'Quadro de vendas: visualizar',
            self::SalesBoardsCreate => 'Quadro de vendas: criar',
            self::SalesBoardsUpdate => 'Quadro de vendas: editar',
            self::SalesBoardsDelete => 'Quadro de vendas: excluir',
            self::ReceivablesView => 'Recebíveis: visualizar',
            self::ReceivablesCreate => 'Recebíveis: criar',
            self::ReceivablesUpdate => 'Recebíveis: editar',
            self::ReceivablesDelete => 'Recebíveis: excluir',
            self::RecruitmentVacanciesView => 'Vagas: visualizar',
            self::RecruitmentVacanciesCreate => 'Vagas: criar',
            self::RecruitmentVacanciesUpdate => 'Vagas: editar',
            self::RecruitmentVacanciesDelete => 'Vagas: excluir',
            self::RecruitmentApplicationsView => 'Candidaturas: visualizar',
            self::RecruitmentApplicationsUpdate => 'Candidaturas: editar',
            self::RecruitmentApplicationsDelete => 'Candidaturas: excluir',
            self::NimbusSubmissionsView => 'Gestão Documental Externa envios: visualizar',
            self::NimbusSubmissionsUpdate => 'Gestão Documental Externa envios: editar',
            self::NimbusSubmissionsDelete => 'Gestão Documental Externa envios: excluir',
            self::NimbusPortalUsersView => 'Gestão Documental Externa usuários: visualizar',
            self::NimbusPortalUsersCreate => 'Gestão Documental Externa usuários: criar',
            self::NimbusPortalUsersUpdate => 'Gestão Documental Externa usuários: editar',
            self::NimbusPortalUsersDelete => 'Gestão Documental Externa usuários: excluir',
            self::NimbusAccessTokensView => 'Gestão Documental Externa chaves: visualizar',
            self::NimbusAccessTokensCreate => 'Gestão Documental Externa chaves: criar',
            self::NimbusAccessTokensUpdate => 'Gestão Documental Externa chaves: editar',
            self::NimbusAccessTokensDelete => 'Gestão Documental Externa chaves: excluir',
            self::NimbusDocumentCategoriesView => 'Gestão Documental Externa categorias: visualizar',
            self::NimbusDocumentCategoriesCreate => 'Gestão Documental Externa categorias: criar',
            self::NimbusDocumentCategoriesUpdate => 'Gestão Documental Externa categorias: editar',
            self::NimbusDocumentCategoriesDelete => 'Gestão Documental Externa categorias: excluir',
            self::NimbusGeneralDocumentsView => 'Gestão Documental Externa biblioteca geral: visualizar',
            self::NimbusGeneralDocumentsCreate => 'Gestão Documental Externa biblioteca geral: criar',
            self::NimbusGeneralDocumentsUpdate => 'Gestão Documental Externa biblioteca geral: editar',
            self::NimbusGeneralDocumentsDelete => 'Gestão Documental Externa biblioteca geral: excluir',
            self::NimbusPortalDocumentsView => 'Gestão Documental Externa documentos por usuário: visualizar',
            self::NimbusPortalDocumentsCreate => 'Gestão Documental Externa documentos por usuário: criar',
            self::NimbusPortalDocumentsUpdate => 'Gestão Documental Externa documentos por usuário: editar',
            self::NimbusPortalDocumentsDelete => 'Gestão Documental Externa documentos por usuário: excluir',
            self::NimbusAnnouncementsView => 'Gestão Documental Externa avisos: visualizar',
            self::NimbusAnnouncementsCreate => 'Gestão Documental Externa avisos: criar',
            self::NimbusAnnouncementsUpdate => 'Gestão Documental Externa avisos: editar',
            self::NimbusAnnouncementsDelete => 'Gestão Documental Externa avisos: excluir',
            self::NimbusNotificationOutboxesView => 'Gestão Documental Externa auditoria de envios: visualizar',
            self::NimbusNotificationSettingsView => 'Gestão Documental Externa notificações: visualizar',
            self::NimbusNotificationSettingsUpdate => 'Gestão Documental Externa notificações: editar',
            self::AuditActivitiesView => 'Auditoria logs do sistema: visualizar',
            self::AuditDocumentDownloadsView => 'Auditoria downloads do portal: visualizar',
            self::ReportsView => 'Relatórios: visualizar',
            self::SettingsView => 'Configurações: visualizar',
        };
    }

    public function group(): string
    {
        return match (true) {
            str_starts_with($this->value, 'users.'),
            str_starts_with($this->value, 'roles.'),
            str_starts_with($this->value, 'invitations.') => 'Configurações',
            str_starts_with($this->value, 'nimbus.') => 'Gestão Documental Externa',
            str_starts_with($this->value, 'recruitment.') => 'Recrutamento',
            str_starts_with($this->value, 'audit.') => 'Auditoria',
            str_starts_with($this->value, 'proposal') => 'Comercial',
            in_array($this, [
                self::InvestorsView,
                self::InvestorsCreate,
                self::InvestorsUpdate,
                self::InvestorsDelete,
                self::FundsView,
                self::FundsCreate,
                self::FundsUpdate,
                self::FundsDelete,
                self::DocumentsView,
                self::DocumentsCreate,
                self::DocumentsUpdate,
                self::DocumentsDelete,
                self::ConstructionsView,
                self::ConstructionsCreate,
                self::ConstructionsUpdate,
                self::ConstructionsDelete,
            ], true) => 'Cadastro',
            in_array($this, [
                self::EmissionsView,
                self::EmissionsCreate,
                self::EmissionsUpdate,
                self::EmissionsDelete,
                self::ExpensesView,
                self::ExpensesCreate,
                self::ExpensesUpdate,
                self::ExpensesDelete,
                self::SalesBoardsView,
                self::SalesBoardsCreate,
                self::SalesBoardsUpdate,
                self::SalesBoardsDelete,
                self::ReceivablesView,
                self::ReceivablesCreate,
                self::ReceivablesUpdate,
                self::ReceivablesDelete,
            ], true) => 'Gestão',
            default => 'Outros',
        };
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function groupedOptions(): array
    {
        $options = [];

        foreach (self::cases() as $permission) {
            $options[$permission->group()][$permission->value] = $permission->label();
        }

        return $options;
    }
}

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
    case ObligationsView = 'obligations.view';
    case ObligationsCreate = 'obligations.create';
    case ObligationsUpdate = 'obligations.update';
    case ObligationsDelete = 'obligations.delete';
    case ObligationsGenerate = 'obligations.generate';
    case ObligationsReviewSuggestions = 'obligations.review_suggestions';
    case ObligationsApproveSuggestion = 'obligations.approve_suggestion';
    case ObligationsRejectSuggestion = 'obligations.reject_suggestion';
    case ObligationsViewDashboard = 'obligations.view_dashboard';
    case ObligationsSubmitForReview = 'obligations.submit_for_review';
    case ObligationsComplete = 'obligations.complete';
    case ObligationsMarkNotApplicable = 'obligations.mark_not_applicable';
    case ObligationsReopen = 'obligations.reopen';
    case ObligationsUploadEvidence = 'obligations.upload_evidence';
    case ObligationsViewEvidence = 'obligations.view_evidence';
    case ObligationsDownloadEvidence = 'obligations.download_evidence';
    case ObligationsDeleteEvidence = 'obligations.delete_evidence';
    case ObligationsApproveEvidence = 'obligations.approve_evidence';
    case ObligationsRejectEvidence = 'obligations.reject_evidence';
    case ObligationsViewHistory = 'obligations.view_history';
    case ObligationsViewComments = 'obligations.view_comments';
    case ObligationsCreateComment = 'obligations.create_comment';
    case ObligationsUpdateComment = 'obligations.update_comment';
    case ObligationsDeleteComment = 'obligations.delete_comment';
    case ObligationsSendNotifications = 'obligations.send_notifications';
    case ObligationsExport = 'obligations.export';
    case GuaranteesView = 'guarantees.view';
    case GuaranteesCreate = 'guarantees.create';
    case GuaranteesUpdate = 'guarantees.update';
    case GuaranteesDelete = 'guarantees.delete';
    case GuaranteesGenerate = 'guarantees.generate';
    case GuaranteesReviewSuggestions = 'guarantees.review_suggestions';
    case GuaranteesApproveSuggestion = 'guarantees.approve_suggestion';
    case GuaranteesRejectSuggestion = 'guarantees.reject_suggestion';
    case GuaranteesComplementGuarantee = 'guarantees.complement_guarantee';
    case GuaranteesReprocessDocuments = 'guarantees.reprocess_documents';
    case GuaranteesUpdateValue = 'guarantees.update_value';
    case GuaranteesCloseCompetence = 'guarantees.close_competence';
    case GuaranteesReopenCompetence = 'guarantees.reopen_competence';
    case GuaranteesManageValuations = 'guarantees.manage_valuations';
    case GuaranteesReleaseGuarantee = 'guarantees.release';
    case LegalInstrumentsView = 'legal-instruments.view';
    case LegalInstrumentsCreate = 'legal-instruments.create';
    case LegalInstrumentsUpdate = 'legal-instruments.update';
    case LegalInstrumentsDelete = 'legal-instruments.delete';
    case LegalInstrumentsAttachDocument = 'legal-instruments.attach_document';
    case LegalInstrumentsDetachDocument = 'legal-instruments.detach_document';
    case LegalInstrumentsProcessDocument = 'legal-instruments.process_document';
    case LegalInstrumentsReviewChanges = 'legal-instruments.review_changes';
    case LegalInstrumentsConfirmChange = 'legal-instruments.confirm_change';
    case LegalInstrumentsRejectChange = 'legal-instruments.reject_change';
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
    case ClientsView = 'clients.view';
    case ClientsCreate = 'clients.create';
    case ClientsUpdate = 'clients.update';
    case ClientsDelete = 'clients.delete';
    case ClientsRestore = 'clients.restore';
    case ContractsView = 'contracts.view';
    case ContractsCreate = 'contracts.create';
    case ContractsUpdate = 'contracts.update';
    case ContractsDelete = 'contracts.delete';
    case ContractsRestore = 'contracts.restore';
    case ContractInstallmentsView = 'contract-installments.view';
    case ContractInstallmentsCreate = 'contract-installments.create';
    case ContractInstallmentsUpdate = 'contract-installments.update';
    case ContractInstallmentsDelete = 'contract-installments.delete';
    case ContractInstallmentsRestore = 'contract-installments.restore';
    case SalesBoardsView = 'sales-boards.view';
    case SalesBoardsCreate = 'sales-boards.create';
    case SalesBoardsUpdate = 'sales-boards.update';
    case SalesBoardsDelete = 'sales-boards.delete';
    case ReceivablesView = 'receivables.view';
    case ReceivablesCreate = 'receivables.create';
    case ReceivablesUpdate = 'receivables.update';
    case ReceivablesDelete = 'receivables.delete';
    case NegotiationsView = 'negotiations.view';
    case NegotiationsCreate = 'negotiations.create';
    case NegotiationsUpdate = 'negotiations.update';
    case NegotiationsDelete = 'negotiations.delete';
    case OperationsView = 'operations.view';
    case OperationsCreate = 'operations.create';
    case OperationsUpdate = 'operations.update';
    case OperationsManageResponsibilities = 'operations.manage-responsibilities';
    case OperationsDelete = 'operations.delete';
    case MeasurementsView = 'measurements.view';
    case MeasurementsCreate = 'measurements.create';
    case MeasurementsUpdate = 'measurements.update';
    case MeasurementsDelete = 'measurements.delete';
    case MeasurementsReview = 'measurements.review';
    case MeasurementsPay = 'measurements.pay';
    case MeasurementsReceipts = 'measurements.receipts';
    case MeasurementsFinalize = 'measurements.finalize';
    case MeasurementsExport = 'measurements.export';
    case MeasurementsExceptionsView = 'measurements.exceptions.view';
    case MeasurementsCycleReportsView = 'measurements.cycle-reports.view';
    case MeasurementsCycleReportsExport = 'measurements.cycle-reports.export';
    case MeasurementsFinancialRulesView = 'measurements.financial-rules.view';
    case MeasurementsFinancialRulesManage = 'measurements.financial-rules.manage';
    case DelegationsView = 'delegations.view';
    case DelegationsCreate = 'delegations.create';
    case DelegationsRevoke = 'delegations.revoke';
    case DelegationsManage = 'delegations.manage';
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
    case PuCurveView = 'pu.curve.view';
    case PuParametersConfigure = 'pu.parameters.configure';
    case PuCurveGenerate = 'pu.curve.generate';
    case PuCurveValidate = 'pu.curve.validate';
    case PuCurveExport = 'pu.curve.export';
    case PuCurveReprocess = 'pu.curve.reprocess';
    case PuCurveHomologate = 'pu.curve.homologate';
    case PuCurveInvalidate = 'pu.curve.invalidate';
    case PuCurvePromote = 'pu.curve.promote';
    case PuDashboardView = 'pu.dashboard.view';
    case PuIndexImport = 'pu.index.import';
    case PuIndexSync = 'pu.index.sync';
    case PuProjectionApprove = 'pu.projection.approve';
    case PuCalendarManage = 'pu.calendar.manage';
    case PuHolidayImport = 'pu.holiday.import';
    case PuCalendarHomologationExecute = 'pu.calendar-homologation.execute';
    case PuCalendarHomologationReview = 'pu.calendar-homologation.review';
    case PuCalendarHomologationApply = 'pu.calendar-homologation.apply';
    case ContactMessagesView = 'contact-messages.view';
    case ContactMessagesUpdate = 'contact-messages.update';
    case ReminderLogsView = 'reminder-logs.view';
    case AuditActivitiesView = 'audit.activities.view';
    case AuditDocumentDownloadsView = 'audit.document-downloads.view';
    case AuditImportRunsView = 'audit.import-runs.view';
    case ReportsView = 'reports.view';
    case ReportsCommentsView = 'reports.comments.view';
    case ReportsCommentsCreate = 'reports.comments.create';
    case ReportsCommentsUpdate = 'reports.comments.update';
    case ReportsCommentsDelete = 'reports.comments.delete';
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
            self::ObligationsView => 'Obrigações: visualizar',
            self::ObligationsCreate => 'Obrigações: criar',
            self::ObligationsUpdate => 'Obrigações: editar',
            self::ObligationsDelete => 'Obrigações: excluir',
            self::ObligationsGenerate => 'Obrigações: gerar do Termo',
            self::ObligationsReviewSuggestions => 'Obrigações: revisar sugestões do Termo',
            self::ObligationsApproveSuggestion => 'Obrigações: aprovar sugestões do Termo',
            self::ObligationsRejectSuggestion => 'Obrigações: rejeitar sugestões do Termo',
            self::ObligationsViewDashboard => 'Obrigações: visualizar painel operacional',
            self::ObligationsSubmitForReview => 'Obrigações: enviar para análise',
            self::ObligationsComplete => 'Obrigações: concluir',
            self::ObligationsMarkNotApplicable => 'Obrigações: marcar como não aplicável',
            self::ObligationsReopen => 'Obrigações: reabrir',
            self::ObligationsUploadEvidence => 'Obrigações: anexar evidências',
            self::ObligationsViewEvidence => 'Obrigações: visualizar evidências',
            self::ObligationsDownloadEvidence => 'Obrigações: baixar evidências',
            self::ObligationsDeleteEvidence => 'Obrigações: remover evidências',
            self::ObligationsApproveEvidence => 'Obrigações: aprovar evidências',
            self::ObligationsRejectEvidence => 'Obrigações: rejeitar evidências',
            self::ObligationsViewHistory => 'Obrigações: visualizar histórico',
            self::ObligationsViewComments => 'Obrigações: visualizar comentários internos',
            self::ObligationsCreateComment => 'Obrigações: criar comentários internos',
            self::ObligationsUpdateComment => 'Obrigações: editar comentários internos',
            self::ObligationsDeleteComment => 'Obrigações: remover comentários internos',
            self::ObligationsSendNotifications => 'Obrigações: enviar notificações',
            self::ObligationsExport => 'Obrigações: exportar',
            self::GuaranteesView => 'Garantias: visualizar',
            self::GuaranteesCreate => 'Garantias: criar',
            self::GuaranteesUpdate => 'Garantias: editar',
            self::GuaranteesDelete => 'Garantias: excluir',
            self::GuaranteesGenerate => 'Garantias: identificar nos documentos',
            self::GuaranteesReviewSuggestions => 'Garantias: revisar garantias detectadas',
            self::GuaranteesApproveSuggestion => 'Garantias: confirmar garantia detectada',
            self::GuaranteesRejectSuggestion => 'Garantias: rejeitar garantia detectada',
            self::GuaranteesComplementGuarantee => 'Garantias: complementar garantia existente',
            self::GuaranteesReprocessDocuments => 'Garantias: reprocessar documentos',
            self::GuaranteesUpdateValue => 'Garantias: atualizar valor da competência',
            self::GuaranteesCloseCompetence => 'Garantias: fechar competência',
            self::GuaranteesReopenCompetence => 'Garantias: reabrir competência',
            self::GuaranteesManageValuations => 'Garantias: registrar avaliações',
            self::GuaranteesReleaseGuarantee => 'Garantias: liberar ou substituir',
            self::LegalInstrumentsView => 'Instrumentos jurídicos: visualizar',
            self::LegalInstrumentsCreate => 'Instrumentos jurídicos: criar',
            self::LegalInstrumentsUpdate => 'Instrumentos jurídicos: editar',
            self::LegalInstrumentsDelete => 'Instrumentos jurídicos: excluir',
            self::LegalInstrumentsAttachDocument => 'Instrumentos jurídicos: anexar documento ao dossiê',
            self::LegalInstrumentsDetachDocument => 'Instrumentos jurídicos: remover documento do dossiê',
            self::LegalInstrumentsProcessDocument => 'Instrumentos jurídicos: processar documento',
            self::LegalInstrumentsReviewChanges => 'Instrumentos jurídicos: revisar alterações detectadas',
            self::LegalInstrumentsConfirmChange => 'Instrumentos jurídicos: confirmar alteração',
            self::LegalInstrumentsRejectChange => 'Instrumentos jurídicos: rejeitar alteração',
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
            self::ClientsView => 'Clientes: visualizar',
            self::ClientsCreate => 'Clientes: criar',
            self::ClientsUpdate => 'Clientes: editar',
            self::ClientsDelete => 'Clientes: excluir',
            self::ClientsRestore => 'Clientes: restaurar',
            self::ContractsView => 'Contratos: visualizar',
            self::ContractsCreate => 'Contratos: criar',
            self::ContractsUpdate => 'Contratos: editar',
            self::ContractsDelete => 'Contratos: excluir',
            self::ContractsRestore => 'Contratos: restaurar',
            self::ContractInstallmentsView => 'Parcelas dos contratos: visualizar',
            self::ContractInstallmentsCreate => 'Parcelas dos contratos: criar',
            self::ContractInstallmentsUpdate => 'Parcelas dos contratos: editar',
            self::ContractInstallmentsDelete => 'Parcelas dos contratos: excluir',
            self::ContractInstallmentsRestore => 'Parcelas dos contratos: restaurar',
            self::SalesBoardsView => 'Quadro de vendas: visualizar',
            self::SalesBoardsCreate => 'Quadro de vendas: criar',
            self::SalesBoardsUpdate => 'Quadro de vendas: editar',
            self::SalesBoardsDelete => 'Quadro de vendas: excluir',
            self::ReceivablesView => 'Recebíveis: visualizar',
            self::ReceivablesCreate => 'Recebíveis: criar',
            self::ReceivablesUpdate => 'Recebíveis: editar',
            self::ReceivablesDelete => 'Recebíveis: excluir',
            self::NegotiationsView => 'Negociações: visualizar',
            self::NegotiationsCreate => 'Negociações: criar',
            self::NegotiationsUpdate => 'Negociações: editar',
            self::NegotiationsDelete => 'Negociações: excluir',
            self::OperationsView => 'Operações de obra: visualizar',
            self::OperationsCreate => 'Operações de obra: criar',
            self::OperationsUpdate => 'Operações de obra: editar',
            self::OperationsManageResponsibilities => 'Operações de obra: gerir responsáveis do workflow',
            self::OperationsDelete => 'Operações de obra: excluir',
            self::MeasurementsView => 'Medições: visualizar',
            self::MeasurementsCreate => 'Medições: criar',
            self::MeasurementsUpdate => 'Medições: editar',
            self::MeasurementsDelete => 'Medições: excluir',
            self::MeasurementsReview => 'Medições: analisar (aprovar/recusar/pausar)',
            self::MeasurementsPay => 'Medições: registrar e analisar pagamentos',
            self::MeasurementsReceipts => 'Medições: anexar e remover comprovantes',
            self::MeasurementsFinalize => 'Medições: finalizar',
            self::MeasurementsExport => 'Medições: exportar pagamentos operacionais',
            self::MeasurementsExceptionsView => 'Medições: visualizar exceções operacionais',
            self::MeasurementsCycleReportsView => 'Medições: visualizar histórico de ciclo',
            self::MeasurementsCycleReportsExport => 'Medições: exportar relatório de ciclo',
            self::MeasurementsFinancialRulesView => 'Medições: visualizar regras financeiras',
            self::MeasurementsFinancialRulesManage => 'Medições: gerenciar regras financeiras',
            self::DelegationsView => 'Delegações: visualizar',
            self::DelegationsCreate => 'Delegações: criar',
            self::DelegationsRevoke => 'Delegações: revogar',
            self::DelegationsManage => 'Delegações: gerir todas',
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
            self::PuCurveView => 'Curva de PU: visualizar',
            self::PuParametersConfigure => 'Curva de PU: configurar parâmetros',
            self::PuCurveGenerate => 'Curva de PU: gerar',
            self::PuCurveValidate => 'Curva de PU: validar',
            self::PuCurveExport => 'Curva de PU: exportar',
            self::PuCurveReprocess => 'Curva de PU: reprocessar',
            self::PuCurveHomologate => 'Curva de PU: homologar',
            self::PuCurveInvalidate => 'Curva de PU: invalidar',
            self::PuCurvePromote => 'Curva de PU: promover candidate a operacional',
            self::PuDashboardView => 'Curva de PU: painel operacional',
            self::PuIndexImport => 'Curva de PU: importar índices',
            self::PuIndexSync => 'Curva de PU: sincronizar índices (Banco Central)',
            self::PuProjectionApprove => 'Curva de PU: aprovar série projetada',
            self::PuCalendarManage => 'Curva de PU: completar calendário de dias úteis',
            self::PuHolidayImport => 'Curva de PU: importar feriados ANBIMA',
            self::PuCalendarHomologationExecute => 'Homologação de calendário CDI: executar',
            self::PuCalendarHomologationReview => 'Homologação de calendário CDI: revisar',
            self::PuCalendarHomologationApply => 'Homologação de calendário CDI: aplicar configuração futura',
            self::ContactMessagesView => 'Mensagens de contato: visualizar',
            self::ContactMessagesUpdate => 'Mensagens de contato: registrar atendimento',
            self::ReminderLogsView => 'Auditoria de lembretes: visualizar',
            self::AuditActivitiesView => 'Auditoria logs do sistema: visualizar',
            self::AuditDocumentDownloadsView => 'Auditoria downloads do portal: visualizar',
            self::AuditImportRunsView => 'Histórico de importações: visualizar',
            self::ReportsView => 'Relatórios: visualizar',
            self::ReportsCommentsView => 'Relatórios — comentários: visualizar',
            self::ReportsCommentsCreate => 'Relatórios — comentários: criar',
            self::ReportsCommentsUpdate => 'Relatórios — comentários: editar',
            self::ReportsCommentsDelete => 'Relatórios — comentários: excluir',
            self::SettingsView => 'Configurações: visualizar',
        };
    }

    public function group(): string
    {
        return match (true) {
            str_starts_with($this->value, 'users.'),
            str_starts_with($this->value, 'roles.'),
            str_starts_with($this->value, 'invitations.') => 'Acessos Externos',
            str_starts_with($this->value, 'nimbus.') => 'Gestão Documental Externa',
            str_starts_with($this->value, 'recruitment.') => 'Recrutamento',
            str_starts_with($this->value, 'pu.') => 'Curva de PU',
            str_starts_with($this->value, 'audit.') => 'Auditoria',
            str_starts_with($this->value, 'reminder-logs.') => 'Auditoria',
            str_starts_with($this->value, 'reports.') => 'Relatórios',
            str_starts_with($this->value, 'proposal') => 'Comercial',
            str_starts_with($this->value, 'contact-messages.') => 'Comercial',
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
                self::ClientsView,
                self::ClientsCreate,
                self::ClientsUpdate,
                self::ClientsDelete,
                self::ClientsRestore,
                self::ContractsView,
                self::ContractsCreate,
                self::ContractsUpdate,
                self::ContractsDelete,
                self::ContractsRestore,
                self::ContractInstallmentsView,
                self::ContractInstallmentsCreate,
                self::ContractInstallmentsUpdate,
                self::ContractInstallmentsDelete,
                self::ContractInstallmentsRestore,
            ], true) => 'Cadastro',
            in_array($this, [
                self::EmissionsView,
                self::EmissionsCreate,
                self::EmissionsUpdate,
                self::EmissionsDelete,
                self::ObligationsView,
                self::ObligationsCreate,
                self::ObligationsUpdate,
                self::ObligationsDelete,
                self::ObligationsGenerate,
                self::ObligationsReviewSuggestions,
                self::ObligationsApproveSuggestion,
                self::ObligationsRejectSuggestion,
                self::ObligationsViewDashboard,
                self::ObligationsSubmitForReview,
                self::ObligationsComplete,
                self::ObligationsMarkNotApplicable,
                self::ObligationsReopen,
                self::ObligationsUploadEvidence,
                self::ObligationsViewEvidence,
                self::ObligationsDownloadEvidence,
                self::ObligationsDeleteEvidence,
                self::ObligationsApproveEvidence,
                self::ObligationsRejectEvidence,
                self::ObligationsViewHistory,
                self::ObligationsViewComments,
                self::ObligationsCreateComment,
                self::ObligationsUpdateComment,
                self::ObligationsDeleteComment,
                self::ObligationsSendNotifications,
                self::ObligationsExport,
                self::GuaranteesView,
                self::GuaranteesCreate,
                self::GuaranteesUpdate,
                self::GuaranteesDelete,
                self::GuaranteesGenerate,
                self::GuaranteesReviewSuggestions,
                self::GuaranteesApproveSuggestion,
                self::GuaranteesRejectSuggestion,
                self::GuaranteesComplementGuarantee,
                self::GuaranteesReprocessDocuments,
                self::GuaranteesUpdateValue,
                self::GuaranteesCloseCompetence,
                self::GuaranteesReopenCompetence,
                self::GuaranteesManageValuations,
                self::GuaranteesReleaseGuarantee,
                self::LegalInstrumentsView,
                self::LegalInstrumentsCreate,
                self::LegalInstrumentsUpdate,
                self::LegalInstrumentsDelete,
                self::LegalInstrumentsAttachDocument,
                self::LegalInstrumentsDetachDocument,
                self::LegalInstrumentsProcessDocument,
                self::LegalInstrumentsReviewChanges,
                self::LegalInstrumentsConfirmChange,
                self::LegalInstrumentsRejectChange,
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
                self::NegotiationsView,
                self::NegotiationsCreate,
                self::NegotiationsUpdate,
                self::NegotiationsDelete,
                self::OperationsView,
                self::OperationsCreate,
                self::OperationsUpdate,
                self::OperationsManageResponsibilities,
                self::OperationsDelete,
                self::DelegationsView,
                self::DelegationsCreate,
                self::DelegationsRevoke,
                self::DelegationsManage,
                self::MeasurementsView,
                self::MeasurementsCreate,
                self::MeasurementsUpdate,
                self::MeasurementsDelete,
                self::MeasurementsReview,
                self::MeasurementsPay,
                self::MeasurementsReceipts,
                self::MeasurementsFinalize,
                self::MeasurementsExport,
                self::MeasurementsExceptionsView,
                self::MeasurementsCycleReportsView,
                self::MeasurementsCycleReportsExport,
                self::MeasurementsFinancialRulesView,
                self::MeasurementsFinancialRulesManage,
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

    public function module(): string
    {
        return match (true) {
            str_starts_with($this->value, 'emissions.'),
            str_starts_with($this->value, 'sales-boards.'),
            str_starts_with($this->value, 'receivables.'),
            str_starts_with($this->value, 'negotiations.'),
            str_starts_with($this->value, 'operations.'),
            str_starts_with($this->value, 'measurements.'),
            str_starts_with($this->value, 'delegations.') => 'Emissões & Operações',

            str_starts_with($this->value, 'obligations.') => 'Obrigações',

            str_starts_with($this->value, 'guarantees.'),
            str_starts_with($this->value, 'legal-instruments.') => 'Garantias & Instrumentos Jurídicos',

            str_starts_with($this->value, 'documents.'),
            str_starts_with($this->value, 'investors.'),
            str_starts_with($this->value, 'funds.'),
            str_starts_with($this->value, 'constructions.'),
            str_starts_with($this->value, 'expenses.') => 'Documentos & Cadastros',

            str_starts_with($this->value, 'clients.'),
            str_starts_with($this->value, 'contracts.'),
            str_starts_with($this->value, 'contract-installments.') => 'Clientes & Contratos',

            str_starts_with($this->value, 'proposals.'),
            str_starts_with($this->value, 'proposal-representatives.'),
            str_starts_with($this->value, 'contact-messages.') => 'Comercial & Propostas',

            str_starts_with($this->value, 'pu.') => 'Curva de PU & Índices',

            str_starts_with($this->value, 'recruitment.') => 'Recrutamento & Vagas',

            str_starts_with($this->value, 'nimbus.') => 'Portal Nimbus (Gestão Documental)',

            str_starts_with($this->value, 'audit.'),
            str_starts_with($this->value, 'reminder-logs.') => 'Auditoria & Rastreabilidade',

            str_starts_with($this->value, 'reports.') => 'Relatórios',

            str_starts_with($this->value, 'users.'),
            str_starts_with($this->value, 'roles.'),
            str_starts_with($this->value, 'invitations.'),
            str_starts_with($this->value, 'settings.') => 'Administração & Usuários',

            default => 'Outros',
        };
    }

    public function isCritical(): bool
    {
        return str_ends_with($this->value, '.delete')
            || str_contains($this->value, '.delete')
            || str_contains($this->value, '.invalidate')
            || str_contains($this->value, '.release')
            || str_contains($this->value, 'manage-responsibilities')
            || str_contains($this->value, 'roles.')
            || str_contains($this->value, 'permissions');
    }
}

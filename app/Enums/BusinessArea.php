<?php

namespace App\Enums;

/**
 * Áreas de negócio da plataforma. Cada área tem responsáveis cadastrados em
 * Configurações > Áreas e responsáveis, e cada permissão do sistema pertence a
 * exatamente uma área (pelo prefixo).
 *
 * Ser responsável por uma área não concede permissão nenhuma: as ações
 * continuam exigindo as permissões do perfil. O cadastro só habilita as regras
 * específicas de cada área, descritas em {@see self::responsibleRule()}.
 *
 * Acrescentar um caso exige uma migration que insira a linha em `areas`.
 */
enum BusinessArea: string
{
    case PuCurve = 'pu_curve';
    case Emissions = 'emissions';
    case Obligations = 'obligations';
    case Guarantees = 'guarantees';
    case LegalInstruments = 'legal_instruments';
    case ConstructionOperations = 'construction_operations';
    case SalesBoards = 'sales_boards';
    case ClientsContracts = 'clients_contracts';
    case Registrations = 'registrations';
    case Commercial = 'commercial';
    case Recruitment = 'recruitment';
    case ExternalDocuments = 'external_documents';
    case Reports = 'reports';
    case Administration = 'administration';

    public function label(): string
    {
        return match ($this) {
            self::PuCurve => 'Curva de PU e Índices',
            self::Emissions => 'Emissões',
            self::Obligations => 'Obrigações',
            self::Guarantees => 'Garantias',
            self::LegalInstruments => 'Instrumentos Jurídicos',
            self::ConstructionOperations => 'Obras, Operações e Medições',
            self::SalesBoards => 'Quadro de Vendas e Recebíveis',
            self::ClientsContracts => 'Clientes e Contratos',
            self::Registrations => 'Cadastros, Fundos e Documentos',
            self::Commercial => 'Comercial e Propostas',
            self::Recruitment => 'Recrutamento',
            self::ExternalDocuments => 'Gestão Documental Externa',
            self::Reports => 'Relatórios',
            self::Administration => 'Administração, Acessos e Auditoria',
        };
    }

    /**
     * O que o responsável pode fazer além das permissões do perfil. Nulo quando
     * a área ainda não tem regra própria.
     */
    public function responsibleRule(): ?string
    {
        return match ($this) {
            self::PuCurve => 'Pode homologar a curva de PU que ele mesmo gerou ou validou, desde que a validação contra planilha tenha passado e com justificativa registrada.',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public function permissionPrefixes(): array
    {
        return match ($this) {
            self::PuCurve => ['pu.'],
            self::Emissions => ['emissions.'],
            self::Obligations => ['obligations.'],
            self::Guarantees => ['guarantees.'],
            self::LegalInstruments => ['legal-instruments.'],
            self::ConstructionOperations => ['constructions.', 'operations.', 'measurements.', 'delegations.'],
            self::SalesBoards => ['sales-boards.', 'receivables.', 'negotiations.'],
            self::ClientsContracts => ['clients.', 'contracts.', 'contract-installments.'],
            self::Registrations => ['investors.', 'funds.', 'documents.', 'expenses.'],
            self::Commercial => ['proposals.', 'proposal-representatives.', 'contact-messages.'],
            self::Recruitment => ['recruitment.'],
            self::ExternalDocuments => ['nimbus.'],
            self::Reports => ['reports.'],
            self::Administration => ['users.', 'roles.', 'invitations.', 'settings.', 'audit.', 'reminder-logs.', 'areas.'],
        };
    }

    public function coversPermission(string $permission): bool
    {
        foreach ($this->permissionPrefixes() as $prefix) {
            if (str_starts_with($permission, $prefix)) {
                return true;
            }
        }

        return false;
    }
}

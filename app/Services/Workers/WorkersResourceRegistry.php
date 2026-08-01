<?php

namespace App\Services\Workers;

use App\Domain\Resource\AncestorBinding;
use App\Domain\Resource\FieldDefinition;
use App\Domain\Resource\QueryCapabilities;
use App\Domain\Resource\RelationDefinition;
use App\Domain\Resource\RelationType;
use App\Domain\Resource\ResourceDefinition;

/**
 * Registre des ResourceDefinitions pour le module HCM Workers.
 *
 * Workers est notre preuve de concept.
 * Toutes les définitions sont exprimées comme métadonnées — aucun "if resource == workers"
 * dans le moteur de requêtes.
 *
 * Hiérarchie couverte :
 *
 *   hcm.workers (depth=1)
 *   └── hcm.workers.workRelationships (depth=2)
 *       └── hcm.workers.workRelationships.assignments (depth=3)
 *           ├── hcm.workers.workRelationships.assignments.managers (depth=4)
 *           ├── hcm.workers.workRelationships.assignments.allReports (depth=4)
 *           ├── hcm.workers.workRelationships.assignments.gradeSteps (depth=4)
 *           ├── hcm.workers.workRelationships.assignments.representatives (depth=4)
 *           └── hcm.workers.workRelationships.assignments.workMeasures (depth=4)
 *
 * Chemins Oracle reference (HCM REST 11.13.18.05) :
 *   GET /hcmRestApi/resources/11.13.18.05/workers
 *   GET /hcmRestApi/resources/11.13.18.05/workers/{workersUniqID}/child/workRelationships
 *   GET /hcmRestApi/resources/11.13.18.05/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments
 *   GET /hcmRestApi/resources/11.13.18.05/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/managers
 */
class WorkersResourceRegistry
{
    private const VERSION = '11.13.18.05';

    private const BASE = '/hcmRestApi/resources/'.self::VERSION;

    /** @return list<ResourceDefinition> */
    public function all(): array
    {
        return [
            $this->workers(),
            $this->workRelationships(),
            $this->assignments(),
            $this->managers(),
            $this->allReports(),
            $this->gradeSteps(),
            $this->representatives(),
            $this->workMeasures(),
            // Ressources racine HCM supplémentaires
            $this->addresses(),
            $this->emails(),
            $this->phones(),
            $this->names(),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Ressource racine : Workers
    // ──────────────────────────────────────────────────────────────────────────

    private function workers(): ResourceDefinition
    {
        return new ResourceDefinition(
            id: 'hcm.workers',
            name: 'workers',
            module: 'hcm',
            label: 'Employés (Workers)',
            description: 'Employés Oracle HCM avec leurs informations personnelles et professionnelles.',
            collectionPath: self::BASE.'/workers',
            itemPath: self::BASE.'/workers/{workersUniqID}',
            apiVersion: self::VERSION,
            capabilities: QueryCapabilities::hcm(),
            fields: [
                FieldDefinition::identifier('workersUniqID', 'string'),
                FieldDefinition::string('PersonNumber'),
                FieldDefinition::string('DisplayName'),
                FieldDefinition::string('FirstName'),
                FieldDefinition::string('LastName'),
                FieldDefinition::string('MiddleNames'),
                FieldDefinition::string('PreferredName'),
                FieldDefinition::date('DateOfBirth'),
                FieldDefinition::string('CountryOfBirth'),
                FieldDefinition::string('RegionOfBirth'),
                FieldDefinition::string('TownOfBirth'),
                FieldDefinition::string('BloodType'),
                FieldDefinition::date('EffectiveStartDate'),
                FieldDefinition::date('EffectiveEndDate'),
                new FieldDefinition('CreatedBy', 'string', false, false),
                FieldDefinition::datetime('CreationDate'),
                FieldDefinition::datetime('LastUpdateDate'),
            ],
            relations: [
                $this->relationWorkersToWorkRelationships(),
                $this->relationWorkersToAddresses(),
                $this->relationWorkersToEmails(),
                $this->relationWorkersToPhones(),
                $this->relationWorkersToNames(),
            ],
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Enfant niveau 2 : WorkRelationships
    // ──────────────────────────────────────────────────────────────────────────

    private function workRelationships(): ResourceDefinition
    {
        return new ResourceDefinition(
            id: 'hcm.workers.workRelationships',
            name: 'workRelationships',
            module: 'hcm',
            label: 'Relations de travail',
            description: 'Relations de travail d\'un employé (contrats, périodes de service).',
            collectionPath: self::BASE.'/workers/{workersUniqID}/child/workRelationships',
            itemPath: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}',
            apiVersion: self::VERSION,
            capabilities: QueryCapabilities::child(),
            fields: [
                FieldDefinition::identifier('PeriodOfServiceId', 'integer'),
                FieldDefinition::string('LegalEmployerName'),
                FieldDefinition::string('WorkerType'),
                FieldDefinition::string('WorkerNumber'),
                FieldDefinition::date('StartDate'),
                FieldDefinition::date('LastWorkingDate'),
                FieldDefinition::boolean('PrimaryFlag'),
                FieldDefinition::string('EnterpriseSeniorityDate'),
                FieldDefinition::datetime('LastUpdateDate'),
            ],
            relations: [
                $this->relationWorkRelationshipsToAssignments(),
            ],
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Enfant niveau 3 : Assignments
    // ──────────────────────────────────────────────────────────────────────────

    private function assignments(): ResourceDefinition
    {
        return new ResourceDefinition(
            id: 'hcm.workers.workRelationships.assignments',
            name: 'assignments',
            module: 'hcm',
            label: 'Affectations',
            description: 'Affectations d\'un employé dans ses relations de travail.',
            collectionPath: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments',
            itemPath: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}',
            apiVersion: self::VERSION,
            capabilities: QueryCapabilities::child(),
            fields: [
                FieldDefinition::identifier('assignmentsUniqID', 'string'),
                FieldDefinition::string('AssignmentNumber'),
                FieldDefinition::string('AssignmentName'),
                FieldDefinition::string('AssignmentType'),
                FieldDefinition::string('AssignmentStatus'),
                FieldDefinition::string('AssignmentStatusType'),
                FieldDefinition::string('BusinessUnitName'),
                FieldDefinition::string('DepartmentName'),
                FieldDefinition::string('JobName'),
                FieldDefinition::string('GradeName'),
                FieldDefinition::string('LocationName'),
                FieldDefinition::string('PositionCode'),
                FieldDefinition::boolean('PrimaryAssignmentFlag'),
                FieldDefinition::date('EffectiveStartDate'),
                FieldDefinition::date('EffectiveEndDate'),
                FieldDefinition::datetime('LastUpdateDate'),
            ],
            relations: [
                $this->relationAssignmentsToManagers(),
                $this->relationAssignmentsToAllReports(),
                $this->relationAssignmentsToGradeSteps(),
                $this->relationAssignmentsToRepresentatives(),
                $this->relationAssignmentsToWorkMeasures(),
            ],
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Enfants niveau 4 : Managers, AllReports, GradeSteps, Representatives, WorkMeasures
    // ──────────────────────────────────────────────────────────────────────────

    private function managers(): ResourceDefinition
    {
        return new ResourceDefinition(
            id: 'hcm.workers.workRelationships.assignments.managers',
            name: 'managers',
            module: 'hcm',
            label: 'Responsables',
            description: 'Responsables hiérarchiques d\'une affectation.',
            collectionPath: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/managers',
            itemPath: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/managers/{managersUniqID}',
            apiVersion: self::VERSION,
            capabilities: QueryCapabilities::child(),
            fields: [
                FieldDefinition::identifier('managersUniqID', 'string'),
                FieldDefinition::string('ManagerId'),
                FieldDefinition::string('ManagerNumber'),
                FieldDefinition::string('ManagerDisplayName'),
                FieldDefinition::string('ManagerType'),
                FieldDefinition::string('AssignmentNumber'),
                FieldDefinition::date('EffectiveStartDate'),
                FieldDefinition::date('EffectiveEndDate'),
            ],
        );
    }

    private function allReports(): ResourceDefinition
    {
        return new ResourceDefinition(
            id: 'hcm.workers.workRelationships.assignments.allReports',
            name: 'allReports',
            module: 'hcm',
            label: 'Tous les subordonnés',
            description: 'Ensemble des subordonnés directs et indirects.',
            collectionPath: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/allReports',
            itemPath: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/allReports/{allReportsUniqID}',
            apiVersion: self::VERSION,
            capabilities: QueryCapabilities::child(),
            fields: [
                FieldDefinition::identifier('allReportsUniqID', 'string'),
                FieldDefinition::string('DirectReportPersonNumber'),
                FieldDefinition::string('DirectReportDisplayName'),
                FieldDefinition::string('ManagerType'),
                FieldDefinition::date('EffectiveStartDate'),
            ],
        );
    }

    private function gradeSteps(): ResourceDefinition
    {
        return new ResourceDefinition(
            id: 'hcm.workers.workRelationships.assignments.gradeSteps',
            name: 'gradeSteps',
            module: 'hcm',
            label: 'Échelons de grade',
            description: 'Échelons de grade associés à l\'affectation.',
            collectionPath: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/gradeSteps',
            itemPath: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/gradeSteps/{gradeStepsUniqID}',
            apiVersion: self::VERSION,
            capabilities: QueryCapabilities::child(),
            fields: [
                FieldDefinition::identifier('gradeStepsUniqID', 'string'),
                FieldDefinition::string('GradeName'),
                FieldDefinition::string('StepName'),
                FieldDefinition::date('EffectiveStartDate'),
            ],
        );
    }

    private function representatives(): ResourceDefinition
    {
        return new ResourceDefinition(
            id: 'hcm.workers.workRelationships.assignments.representatives',
            name: 'representatives',
            module: 'hcm',
            label: 'Représentants',
            description: 'Représentants syndicaux ou légaux liés à l\'affectation.',
            collectionPath: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/representatives',
            itemPath: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/representatives/{representativesUniqID}',
            apiVersion: self::VERSION,
            capabilities: QueryCapabilities::child(),
            fields: [
                FieldDefinition::identifier('representativesUniqID', 'string'),
                FieldDefinition::string('RepresentativeDisplayName'),
                FieldDefinition::string('RepresentativeType'),
                FieldDefinition::date('StartDate'),
            ],
        );
    }

    private function workMeasures(): ResourceDefinition
    {
        return new ResourceDefinition(
            id: 'hcm.workers.workRelationships.assignments.workMeasures',
            name: 'workMeasures',
            module: 'hcm',
            label: 'Mesures de travail',
            description: 'Mesures de temps de travail (FTE, heures) de l\'affectation.',
            collectionPath: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/workMeasures',
            itemPath: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/workMeasures/{workMeasuresUniqID}',
            apiVersion: self::VERSION,
            capabilities: QueryCapabilities::child(),
            fields: [
                FieldDefinition::identifier('workMeasuresUniqID', 'string'),
                FieldDefinition::string('UnitCode'),
                FieldDefinition::string('Value'),
                FieldDefinition::date('EffectiveStartDate'),
            ],
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Enfants racine : Addresses, Emails, Phones, Names
    // ──────────────────────────────────────────────────────────────────────────

    private function addresses(): ResourceDefinition
    {
        return new ResourceDefinition(
            id: 'hcm.workers.addresses',
            name: 'addresses',
            module: 'hcm',
            label: 'Adresses',
            description: 'Adresses personnelles de l\'employé.',
            collectionPath: self::BASE.'/workers/{workersUniqID}/child/addresses',
            itemPath: self::BASE.'/workers/{workersUniqID}/child/addresses/{addressesUniqID}',
            apiVersion: self::VERSION,
            capabilities: QueryCapabilities::child(),
            fields: [
                FieldDefinition::identifier('addressesUniqID', 'string'),
                FieldDefinition::string('AddressType'),
                FieldDefinition::string('AddressLine1'),
                FieldDefinition::string('AddressLine2'),
                FieldDefinition::string('City'),
                FieldDefinition::string('Region'),
                FieldDefinition::string('PostalCode'),
                FieldDefinition::string('Country'),
                FieldDefinition::boolean('PrimaryFlag'),
                FieldDefinition::date('EffectiveStartDate'),
                FieldDefinition::date('EffectiveEndDate'),
            ],
        );
    }

    private function emails(): ResourceDefinition
    {
        return new ResourceDefinition(
            id: 'hcm.workers.emails',
            name: 'emails',
            module: 'hcm',
            label: 'Adresses e-mail',
            description: 'Adresses e-mail professionnelles et personnelles de l\'employé.',
            collectionPath: self::BASE.'/workers/{workersUniqID}/child/emails',
            itemPath: self::BASE.'/workers/{workersUniqID}/child/emails/{emailsUniqID}',
            apiVersion: self::VERSION,
            capabilities: QueryCapabilities::child(),
            fields: [
                FieldDefinition::identifier('emailsUniqID', 'string'),
                FieldDefinition::string('EmailType'),
                FieldDefinition::string('EmailAddress'),
                FieldDefinition::boolean('PrimaryFlag'),
                FieldDefinition::date('EffectiveStartDate'),
                FieldDefinition::date('EffectiveEndDate'),
            ],
        );
    }

    private function phones(): ResourceDefinition
    {
        return new ResourceDefinition(
            id: 'hcm.workers.phones',
            name: 'phones',
            module: 'hcm',
            label: 'Téléphones',
            description: 'Numéros de téléphone de l\'employé.',
            collectionPath: self::BASE.'/workers/{workersUniqID}/child/phones',
            itemPath: self::BASE.'/workers/{workersUniqID}/child/phones/{phonesUniqID}',
            apiVersion: self::VERSION,
            capabilities: QueryCapabilities::child(),
            fields: [
                FieldDefinition::identifier('phonesUniqID', 'string'),
                FieldDefinition::string('PhoneType'),
                FieldDefinition::string('PhoneNumber'),
                FieldDefinition::boolean('PrimaryFlag'),
                FieldDefinition::date('EffectiveStartDate'),
                FieldDefinition::date('EffectiveEndDate'),
            ],
        );
    }

    private function names(): ResourceDefinition
    {
        return new ResourceDefinition(
            id: 'hcm.workers.names',
            name: 'names',
            module: 'hcm',
            label: 'Noms',
            description: 'Variantes de noms de l\'employé (légal, préféré, etc.).',
            collectionPath: self::BASE.'/workers/{workersUniqID}/child/names',
            itemPath: self::BASE.'/workers/{workersUniqID}/child/names/{namesUniqID}',
            apiVersion: self::VERSION,
            capabilities: QueryCapabilities::child(),
            fields: [
                FieldDefinition::identifier('namesUniqID', 'string'),
                FieldDefinition::string('NameType'),
                FieldDefinition::string('FirstName'),
                FieldDefinition::string('LastName'),
                FieldDefinition::string('MiddleNames'),
                FieldDefinition::string('DisplayName'),
                FieldDefinition::date('EffectiveStartDate'),
                FieldDefinition::date('EffectiveEndDate'),
            ],
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Relations CHILD
    // ──────────────────────────────────────────────────────────────────────────

    private function relationWorkersToWorkRelationships(): RelationDefinition
    {
        return new RelationDefinition(
            id: 'hcm.workers→workRelationships',
            sourceId: 'hcm.workers',
            targetId: 'hcm.workers.workRelationships',
            type: RelationType::CHILD,
            label: 'Relations de travail',
            pathTemplate: self::BASE.'/workers/{workersUniqID}/child/workRelationships',
            bindings: [
                new AncestorBinding(
                    placeholder: 'workersUniqID',
                    sourceResourceId: 'hcm.workers',
                    sourceField: 'workersUniqID',
                ),
            ],
        );
    }

    private function relationWorkRelationshipsToAssignments(): RelationDefinition
    {
        return new RelationDefinition(
            id: 'hcm.workers.workRelationships→assignments',
            sourceId: 'hcm.workers.workRelationships',
            targetId: 'hcm.workers.workRelationships.assignments',
            type: RelationType::CHILD,
            label: 'Affectations',
            pathTemplate: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments',
            bindings: [
                new AncestorBinding(
                    placeholder: 'workersUniqID',
                    sourceResourceId: 'hcm.workers',
                    sourceField: 'workersUniqID',
                ),
                new AncestorBinding(
                    placeholder: 'PeriodOfServiceId',
                    sourceResourceId: 'hcm.workers.workRelationships',
                    sourceField: 'PeriodOfServiceId',
                ),
            ],
        );
    }

    private function relationAssignmentsToManagers(): RelationDefinition
    {
        return new RelationDefinition(
            id: 'hcm.workers.workRelationships.assignments→managers',
            sourceId: 'hcm.workers.workRelationships.assignments',
            targetId: 'hcm.workers.workRelationships.assignments.managers',
            type: RelationType::CHILD,
            label: 'Responsables',
            pathTemplate: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/managers',
            bindings: [
                new AncestorBinding('workersUniqID', 'hcm.workers', 'workersUniqID'),
                new AncestorBinding('PeriodOfServiceId', 'hcm.workers.workRelationships', 'PeriodOfServiceId'),
                new AncestorBinding('assignmentsUniqID', 'hcm.workers.workRelationships.assignments', 'assignmentsUniqID'),
            ],
        );
    }

    private function relationAssignmentsToAllReports(): RelationDefinition
    {
        return new RelationDefinition(
            id: 'hcm.workers.workRelationships.assignments→allReports',
            sourceId: 'hcm.workers.workRelationships.assignments',
            targetId: 'hcm.workers.workRelationships.assignments.allReports',
            type: RelationType::CHILD,
            label: 'Tous les subordonnés',
            pathTemplate: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/allReports',
            bindings: [
                new AncestorBinding('workersUniqID', 'hcm.workers', 'workersUniqID'),
                new AncestorBinding('PeriodOfServiceId', 'hcm.workers.workRelationships', 'PeriodOfServiceId'),
                new AncestorBinding('assignmentsUniqID', 'hcm.workers.workRelationships.assignments', 'assignmentsUniqID'),
            ],
        );
    }

    private function relationAssignmentsToGradeSteps(): RelationDefinition
    {
        return new RelationDefinition(
            id: 'hcm.workers.workRelationships.assignments→gradeSteps',
            sourceId: 'hcm.workers.workRelationships.assignments',
            targetId: 'hcm.workers.workRelationships.assignments.gradeSteps',
            type: RelationType::CHILD,
            label: 'Échelons de grade',
            pathTemplate: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/gradeSteps',
            bindings: [
                new AncestorBinding('workersUniqID', 'hcm.workers', 'workersUniqID'),
                new AncestorBinding('PeriodOfServiceId', 'hcm.workers.workRelationships', 'PeriodOfServiceId'),
                new AncestorBinding('assignmentsUniqID', 'hcm.workers.workRelationships.assignments', 'assignmentsUniqID'),
            ],
        );
    }

    private function relationAssignmentsToRepresentatives(): RelationDefinition
    {
        return new RelationDefinition(
            id: 'hcm.workers.workRelationships.assignments→representatives',
            sourceId: 'hcm.workers.workRelationships.assignments',
            targetId: 'hcm.workers.workRelationships.assignments.representatives',
            type: RelationType::CHILD,
            label: 'Représentants',
            pathTemplate: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/representatives',
            bindings: [
                new AncestorBinding('workersUniqID', 'hcm.workers', 'workersUniqID'),
                new AncestorBinding('PeriodOfServiceId', 'hcm.workers.workRelationships', 'PeriodOfServiceId'),
                new AncestorBinding('assignmentsUniqID', 'hcm.workers.workRelationships.assignments', 'assignmentsUniqID'),
            ],
        );
    }

    private function relationAssignmentsToWorkMeasures(): RelationDefinition
    {
        return new RelationDefinition(
            id: 'hcm.workers.workRelationships.assignments→workMeasures',
            sourceId: 'hcm.workers.workRelationships.assignments',
            targetId: 'hcm.workers.workRelationships.assignments.workMeasures',
            type: RelationType::CHILD,
            label: 'Mesures de travail',
            pathTemplate: self::BASE.'/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/workMeasures',
            bindings: [
                new AncestorBinding('workersUniqID', 'hcm.workers', 'workersUniqID'),
                new AncestorBinding('PeriodOfServiceId', 'hcm.workers.workRelationships', 'PeriodOfServiceId'),
                new AncestorBinding('assignmentsUniqID', 'hcm.workers.workRelationships.assignments', 'assignmentsUniqID'),
            ],
        );
    }

    private function relationWorkersToAddresses(): RelationDefinition
    {
        return new RelationDefinition(
            id: 'hcm.workers→addresses',
            sourceId: 'hcm.workers',
            targetId: 'hcm.workers.addresses',
            type: RelationType::CHILD,
            label: 'Adresses',
            pathTemplate: self::BASE.'/workers/{workersUniqID}/child/addresses',
            bindings: [
                new AncestorBinding('workersUniqID', 'hcm.workers', 'workersUniqID'),
            ],
        );
    }

    private function relationWorkersToEmails(): RelationDefinition
    {
        return new RelationDefinition(
            id: 'hcm.workers→emails',
            sourceId: 'hcm.workers',
            targetId: 'hcm.workers.emails',
            type: RelationType::CHILD,
            label: 'Adresses e-mail',
            pathTemplate: self::BASE.'/workers/{workersUniqID}/child/emails',
            bindings: [
                new AncestorBinding('workersUniqID', 'hcm.workers', 'workersUniqID'),
            ],
        );
    }

    private function relationWorkersToPhones(): RelationDefinition
    {
        return new RelationDefinition(
            id: 'hcm.workers→phones',
            sourceId: 'hcm.workers',
            targetId: 'hcm.workers.phones',
            type: RelationType::CHILD,
            label: 'Téléphones',
            pathTemplate: self::BASE.'/workers/{workersUniqID}/child/phones',
            bindings: [
                new AncestorBinding('workersUniqID', 'hcm.workers', 'workersUniqID'),
            ],
        );
    }

    private function relationWorkersToNames(): RelationDefinition
    {
        return new RelationDefinition(
            id: 'hcm.workers→names',
            sourceId: 'hcm.workers',
            targetId: 'hcm.workers.names',
            type: RelationType::CHILD,
            label: 'Noms',
            pathTemplate: self::BASE.'/workers/{workersUniqID}/child/names',
            bindings: [
                new AncestorBinding('workersUniqID', 'hcm.workers', 'workersUniqID'),
            ],
        );
    }
}

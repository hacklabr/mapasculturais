<?php

namespace OpportunityAppealPhase\AppealReview;

use DateTime;
use MapasCulturais\App;
use MapasCulturais\Entities\RegistrationEvaluation;
use MapasCulturais\Exceptions\PermissionDenied;
use MapasCulturais\i;
use OpportunityAppealPhase\Entities\RegistrationAppealReview;
use OpportunityAppealPhase\Module;

/**
 * Monta o payload read-only do ambiente do corretor (PR4.5 / issue #14).
 */
class CorrectorEnvironment
{
    private const STATUS_LABELS = [
        RegistrationAppealReview::STATUS_DESIGNATED => 'designated',
        RegistrationAppealReview::STATUS_DRAFT => 'draft',
        RegistrationAppealReview::STATUS_SENT => 'sent',
        RegistrationAppealReview::STATUS_REOPENED => 'reopened',
    ];

    /**
     * @return array<string, mixed>
     */
    public function build(RegistrationAppealReview $review): array
    {
        $app = App::i();
        $this->assertFeatureEnabled();
        $this->assertCanAccess($review, $app->user);

        $slot = $review->originalEvaluation;
        $scope_ids = $this->getReleasedCriteriaIds($review);

        $original = $this->filterEvaluationData((array) $slot->getEvaluationData(), $scope_ids);
        $draft = $review->correctedValue
            ? $this->filterEvaluationData((array) $review->correctedValue, $scope_ids)
            : null;

        $main_emc = $slot->registration->opportunity->evaluationMethodConfiguration;
        $method_id = $main_emc ? (string) $main_emc->type->id : '';

        $payload = [
            'assignmentId' => (int) $review->id,
            'evaluationId' => (int) $slot->id,
            'registrationId' => (int) $review->registration->id,
            'registrationNumber' => $review->registration->number,
            'opportunityName' => (string) $review->registration->opportunity->name,
            // F7 (#51): método da fase principal — define como o frontend
            // renderiza o formulário (critérios técnicos, campos documentais
            // ou resultado global do simples).
            'method' => $method_id,
            'criteria' => $this->buildCriteriaMetadata($slot, $scope_ids),
            'targetEvaluatorName' => $this->resolveEvaluatorName($review),
            'deadline' => $review->endsAt ? $review->endsAt->format('c') : null,
            'status' => self::STATUS_LABELS[(int) $review->status] ?? (string) $review->status,
            'correctionType' => $review->correctionType,
            'originalEvaluation' => (object) $original,
            'draft' => $draft !== null ? (object) $draft : null,
        ];

        if ($method_id === 'documentary') {
            $payload['fields'] = $this->buildDocumentaryFields($review, $scope_ids);
        } elseif ($method_id === 'simple') {
            $payload['statusOptions'] = $this->buildSimpleStatusOptions();
        }

        if ($this->shouldShowAppealOpinion($review)) {
            $payload['committeeOpinion'] = $this->buildCommitteeOpinion($review);
        }

        return $payload;
    }

    private function assertCanAccess(RegistrationAppealReview $review, $user): void
    {
        if ($user->is('guest')) {
            throw new PermissionDenied($user, $review, 'correctorEnvironment');
        }

        if (!$review->isActive()) {
            throw new PermissionDenied($user, $review, 'correctorEnvironment');
        }

        if (!$review->correctorUser || !$review->correctorUser->equals($user)) {
            throw new PermissionDenied($user, $review, 'correctorEnvironment');
        }

        $now = new DateTime();
        if ($review->startsAt && $now < $review->startsAt) {
            throw new PermissionDenied($user, $review, 'correctorEnvironment');
        }
        if ($review->endsAt && $now > $review->endsAt) {
            throw new PermissionDenied($user, $review, 'correctorEnvironment');
        }
    }

    private function assertFeatureEnabled(): void
    {
        $module = App::i()->modules['OpportunityAppealPhase'] ?? null;
        $default = false;
        if ($module instanceof Module) {
            $default = (bool) ($module->config['featureFlag.appealScoreCorrection'] ?? false);
        }

        if (!(bool) env('APPEAL_SCORE_CORRECTION', $default)) {
            throw new PermissionDenied(App::i()->user, null, 'correctorEnvironment');
        }
    }

    private function resolveEvaluatorName(RegistrationAppealReview $review): string
    {
        $owner = $review->slotOwnerUser;
        if ($owner && $owner->profile) {
            return (string) $owner->profile->name;
        }

        return $owner ? (string) $owner->email : '';
    }

    /**
     * Metadata of the criteria released for correction, from the MAIN phase
     * evaluation method configuration (not the appeal phase). Criteria ids in
     * the scope that do not exist in the configuration are ignored.
     *
     * @param string[]|null $scope_ids null = all criteria
     * @return array<int, array<string, mixed>>
     */
    private function buildCriteriaMetadata(RegistrationEvaluation $slot, ?array $scope_ids): array
    {
        $emc = $slot->registration->opportunity->evaluationMethodConfiguration;
        if (!$emc) {
            return [];
        }

        $sections = $emc->sections ?? [];
        $criteria = $emc->criteria ?? [];

        // metadata may still be raw JSON strings before unserialize (same normalization
        // as EvaluationMethodConfiguration::validateCriteriaSectionsIntegrity)
        if (is_string($sections)) {
            $sections = json_decode($sections);
        }
        if (is_string($criteria)) {
            $criteria = json_decode($criteria);
        }

        $sections = (array) $sections;
        $criteria = (array) $criteria;

        $section_names = [];
        foreach ($sections as $section) {
            $section = (object) $section;
            if (isset($section->id)) {
                $section_names[(string) $section->id] = (string) ($section->name ?? '');
            }
        }

        $out = [];
        foreach ($criteria as $criterion) {
            $criterion = (object) $criterion;
            if (!isset($criterion->id)) {
                continue;
            }

            $id = (string) $criterion->id;
            if ($scope_ids !== null && !in_array($id, $scope_ids, true)) {
                continue;
            }

            $sid = isset($criterion->sid) ? (string) $criterion->sid : null;

            $out[] = [
                'id' => $id,
                'title' => (string) ($criterion->title ?? $id),
                'min' => (float) ($criterion->min ?? 0),
                'max' => (float) ($criterion->max ?? 0),
                'weight' => (float) ($criterion->weight ?? 1),
                'sectionId' => $sid,
                'sectionName' => $sid !== null ? ($section_names[$sid] ?? '') : null,
            ];
        }

        return $out;
    }

    /**
     * F7 (#51): campos exigidos pela qualificação documental (mesma fonte do
     * formulário documentary-evaluation-form): configurações de campo e de
     * anexo das fases da oportunidade, chaveadas por fieldName/fileGroupName
     * — as mesmas chaves do evaluationData e do releasedScope. Filtrado pelo
     * escopo liberado.
     *
     * @param string[]|null $scope_ids null = todos
     * @return array<int, array<string, mixed>>
     */
    private function buildDocumentaryFields(RegistrationAppealReview $review, ?array $scope_ids): array
    {
        $main_phase = $review->registration->opportunity;

        $items = [];

        foreach ($main_phase->allPhases as $phase) {
            foreach ($phase->registrationFieldConfigurations as $field) {
                $items[$field->getFieldName()] = (string) ($field->title ?? $field->getFieldName());
            }

            foreach ($phase->registrationFileConfigurations as $file) {
                $items[$file->fileGroupName] = (string) ($file->title ?? $file->fileGroupName);
            }
        }

        $out = [];
        foreach ($items as $id => $title) {
            if ($scope_ids !== null && !in_array((string) $id, $scope_ids, true)) {
                continue;
            }

            $out[] = [
                'id' => (string) $id,
                'title' => $title,
            ];
        }

        return $out;
    }

    /**
     * F7 (#51): opções de resultado global do método simples — mesmas
     * values/labels do formulário simple-evaluation-form.
     *
     * @return array<int, array<string, string>>
     */
    private function buildSimpleStatusOptions(): array
    {
        return [
            ['value' => '2', 'label' => i::__('Inválida')],
            ['value' => '3', 'label' => i::__('Não selecionada')],
            ['value' => '8', 'label' => i::__('Suplente')],
            ['value' => '10', 'label' => i::__('Selecionada')],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param string[]|null $scope_ids
     * @return array<string, mixed>
     */
    private function filterEvaluationData(array $data, ?array $scope_ids): array
    {
        if ($scope_ids === null) {
            return $data;
        }

        $filtered = [];
        foreach ($data as $key => $value) {
            if (in_array((string) $key, $scope_ids, true)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    private function shouldShowAppealOpinion(RegistrationAppealReview $review): bool
    {
        $scope = $review->releasedScope;
        if ($scope === null) {
            return false;
        }

        $scope = (array) $scope;
        return !empty($scope['showAppealOpinion']);
    }

    /**
     * Parecer da Comissão de Recursos na fase de recurso (mesma inscrição por number).
     *
     * @return array<string, mixed>|null
     */
    private function buildCommitteeOpinion(RegistrationAppealReview $review): ?array
    {
        $app = App::i();
        $appeal_phase = $review->appealPhase;
        $registration = $review->registration;

        if (!$appeal_phase || !$registration) {
            return null;
        }

        $appeal_registration = $app->repo('Registration')->findOneBy([
            'opportunity' => $appeal_phase,
            'number' => $registration->number,
        ]);

        if (!$appeal_registration) {
            return null;
        }

        /** @var RegistrationEvaluation[] $evaluations */
        $evaluations = $app->repo('RegistrationEvaluation')->findBy([
            'registration' => $appeal_registration,
        ]);

        if (!$evaluations) {
            return null;
        }

        $opinions = [];
        foreach ($evaluations as $evaluation) {
            if ((int) $evaluation->status < RegistrationEvaluation::STATUS_EVALUATED) {
                continue;
            }

            $opinions[] = [
                'evaluationId' => (int) $evaluation->id,
                'result' => $evaluation->result,
                'resultString' => $evaluation->resultString,
                'status' => (int) $evaluation->status,
                'evaluationData' => $evaluation->getEvaluationData(),
                'valuerName' => $evaluation->user->profile->name ?? $evaluation->user->email,
            ];
        }

        if (!$opinions) {
            return null;
        }

        return [
            'registrationId' => (int) $appeal_registration->id,
            'evaluations' => $opinions,
        ];
    }

    /**
     * @return string[]|null
     */
    private function getReleasedCriteriaIds(RegistrationAppealReview $review): ?array
    {
        $scope = $review->releasedScope;
        if ($scope === null) {
            return null;
        }

        $scope = (array) $scope;
        $criteria = $scope['criteria'] ?? $scope['fields'] ?? null;

        if ($criteria === null) {
            return null;
        }

        return array_map('strval', (array) $criteria);
    }
}

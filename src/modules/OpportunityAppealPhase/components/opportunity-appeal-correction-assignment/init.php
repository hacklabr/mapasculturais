<?php
/**
 * Componente: opportunity-appeal-correction-assignment (F2 / issue #18).
 *
 * Interface pública para abrir o modal de designação de corretores por slot
 * (consumida pelo F1 — botão na lista de inscritos — e por qualquer outra
 * integração):
 *
 *     window.dispatchEvent(new CustomEvent(
 *         'opportunity-appeal-correction-assignment:open',
 *         {
 *             detail: {
 *                 // fase principal (avaliativa) da inscrição.
 *                 // Entity do SDK ou objeto com ao menos {id}.
 *                 opportunity: Entity|{id},
 *
 *                 // inscrição da fase principal com recurso deferido
 *                 // (NÃO a inscrição da fase de recurso).
 *                 // Entity do SDK ou objeto com ao menos {id, number?}.
 *                 registration: Entity|{id, number?},
 *             }
 *         }
 *     ));
 *
 * Requisitos:
 * - A view deve importar e renderizar o componente uma única vez:
 *   `$this->import('opportunity-appeal-correction-assignment');`
 *   seguido de `<opportunity-appeal-correction-assignment></opportunity-appeal-correction-assignment>`
 *   (o listener do evento é registrado no mounted() do Vue).
 * - A designação só fica habilitada quando a oportunidade em contexto é uma
 *   fase técnica com fase de recurso ativa e o usuário tem `@control`.
 *
 * Fontes de dados (somente endpoints existentes):
 * - Slots: GET /api/registrationevaluation/find (uma avaliação por avaliador).
 * - Comissão de Recursos: injetada aqui (mesma origem de
 *   RegistrationAppealReview::eligibleCorrectors()).
 * - Criação/leitura de RegistrationAppealReview: API canônica da entidade,
 *   disponível somente quando houver controller registrado para ela
 *   (`endpointAvailable` abaixo). Sem controller, salvar e acompanhar ficam
 *   desabilitados na interface — backend é out of scope da F2.
 *
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\Entities\Opportunity;
use OpportunityAppealPhase\Entities\RegistrationAppealReview;

$config = [
    // Verdadeiro somente quando a entidade possui controller registrado
    // (sem controller não existe /api/registrationappealreview).
    'endpointAvailable' => (bool) $app->getControllerIdByEntity(RegistrationAppealReview::class),

    // Defaults de criação derivados da entidade/PRD (CA-4 é tratado em outra
    // onda; F2 usa correção oficial e janela/escopo livres).
    'statusDesignated' => RegistrationAppealReview::STATUS_DESIGNATED,
    'statusSent' => RegistrationAppealReview::STATUS_SENT,
    'correctionTypeDefault' => RegistrationAppealReview::CORRECTION_TYPE_OFFICIAL,
    'activeStatuses' => RegistrationAppealReview::getActiveStatuses(),

    // opportunityId (fase principal) => id da fase de recurso.
    'appealPhases' => new stdClass(),

    // opportunityId (fase principal) => membros da Comissão de Recursos:
    // [{userId, name}]. Presente somente em contexto elegível.
    'committees' => new stdClass(),
];

$requested_entity = $this->controller->requestedEntity ?? null;
$opportunity = $requested_entity ? $this->getOpportunityFromEntity($requested_entity) : null;

if ($opportunity instanceof Opportunity && $opportunity->canUser('@control')) {
    $appeal_phase = $opportunity->appealPhase ?? null;
    $main_emc = $opportunity->evaluationMethodConfiguration;

    // Espelha os gates de RegistrationAppealReview::eligibleCorrectors():
    // fase principal com método técnico + fase de recurso ativa com EMC.
    $is_eligible_context = $appeal_phase
        && $appeal_phase->status === Opportunity::STATUS_APPEAL_PHASE
        && $appeal_phase->evaluationMethodConfiguration
        && $main_emc
        && $main_emc->type->id === 'technical';

    if ($is_eligible_context) {
        $committee = [];

        foreach ($appeal_phase->evaluationMethodConfiguration->getCommittee(false) as $agent) {
            $user = $agent->user ?? null;
            if (!$user) {
                continue;
            }

            $committee[$user->id] = [
                'userId' => (int) $user->id,
                'name' => (string) $agent->name,
            ];
        }

        $opportunity_id = (int) $opportunity->id;
        $config['appealPhases']->{$opportunity_id} = (int) $appeal_phase->id;
        $config['committees']->{$opportunity_id} = array_values($committee);
    }
}

$app->applyHook('component(opportunity-appeal-correction-assignment).config', [&$config]);

$this->jsObject['config']['appealCorrectionAssignment'] = $config;

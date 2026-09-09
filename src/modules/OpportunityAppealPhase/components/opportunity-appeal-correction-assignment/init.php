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
 * - A designação só fica habilitada quando a oportunidade em contexto é a
 *   fase de recurso ativa de uma fase técnica e o usuário tem `@control`
 *   na fase pai (onde @control cascateia para a fase de recurso).
 *
 * Contexto F1 (#17) — override 2026-09-08 (épica #7, @israelmelo):
 * a coluna "Designar correção" vive na lista de inscritos da FASE DE
 * RECURSO (cada linha É um recurso; botão somente nas linhas com status
 * Deferido = 10). Por isso o gate abaixo exige que a oportunidade em
 * contexto SEJA a própria fase de recurso, e o config expose:
 * - `appealContexts[appealPhaseId] = {mainPhaseId}` — consumido pela tabela
 *   (opportunity-registrations-table) para decidir se a coluna aparece;
 * - `appealPhases[mainPhaseId] = appealPhaseId`, `committees[mainPhaseId]`
 *   e `evaluators[mainPhaseId]` — consumidos pelo modal, que recebe a fase
 *   PRINCIPAL no evento de abertura e a usa como chave.
 * Da linha da fase de recurso, o F1 deriva a inscrição da fase principal
 * pelo `number` herdado (createAppealPhaseRegistration,
 * OpportunityAppealPhase/Module.php:201) — mesmo mecanismo canônico do
 * backend (Module.php:240-243); não existe meta/relation armazenada
 * ligando recurso → inscrição principal.
 *
 * Fontes de dados (somente endpoints existentes):
 * - Slots: GET /api/registrationevaluation/find (uma avaliação por avaliador;
 *   a relação `user` volta ESCALAR — o nome do avaliador não vem da API).
 * - Comissão de Recursos (`committees`) e avaliadores da fase principal
 *   (`evaluators`, mapa {userId: name}): injetados aqui a partir dos comitês
 *   das EMCs (mesma origem de RegistrationAppealReview::eligibleCorrectors()
 *   e da lista de avaliações da oportunidade).
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

    // F1 (#17) — override 2026-09-08: id da FASE DE RECURSO =>
    // {mainPhaseId: int}. Consumido pela opportunity-registrations-table
    // para exibir a coluna de designação na lista de inscritos da fase de
    // recurso; presente somente em contexto elegível.
    'appealContexts' => new stdClass(),

    // opportunityId (fase principal) => {userId: name} dos avaliadores da
    // fase principal (comitê do EMC principal). Necessário porque a API de
    // RegistrationEvaluation não expõe o nome do avaliador (a relação user
    // não expande no @select — volta sempre escalar).
    'evaluators' => new stdClass(),
];

$requested_entity = $this->controller->requestedEntity ?? null;
$opportunity = $requested_entity ? $this->getOpportunityFromEntity($requested_entity) : null;

/*
    F1 (#17) — override 2026-09-08 (épica #7): a coluna "Designar correção"
    pertence à lista de inscritos da FASE DE RECURSO, não à da fase
    principal. Contexto elegível: a oportunidade em contexto É a fase de
    recurso ativa (STATUS_APPEAL_PHASE), com EMC, cuja fase pai é técnica
    com EMC, e o usuário tem @control na fase pai (onde @control cascateia
    para a fase de recurso). Gates espelhados de
    RegistrationAppealReview::eligibleCorrectors().
*/
if ($opportunity instanceof Opportunity && $opportunity->status === Opportunity::STATUS_APPEAL_PHASE) {
    $appeal_phase = $opportunity;
    $main_phase = $appeal_phase->parent ?? null;

    $is_eligible_context = $main_phase
        && $appeal_phase->evaluationMethodConfiguration
        && $main_phase->evaluationMethodConfiguration
        && $main_phase->evaluationMethodConfiguration->type->id === 'technical'
        && $main_phase->canUser('@control');

    if ($is_eligible_context) {
        $committee = [];
        $evaluators = new stdClass();

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

        // Avaliadores da fase principal (donos de slot): comitê do EMC
        // principal (na árvore do parent, pois o contexto é a fase de
        // recurso). Mesma fonte de nomes usada pela lista de avaliações.
        foreach ($main_phase->evaluationMethodConfiguration->getCommittee(false) as $agent) {
            $user = $agent->user ?? null;
            if (!$user) {
                continue;
            }

            $evaluators->{$user->id} = (string) $agent->name;
        }

        $appeal_phase_id = (int) $appeal_phase->id;
        $main_phase_id = (int) $main_phase->id;

        // Modal (chaveado pela fase principal, recebida no evento de abertura)
        $config['appealPhases']->{$main_phase_id} = $appeal_phase_id;
        $config['committees']->{$main_phase_id} = array_values($committee);
        $config['evaluators']->{$main_phase_id} = $evaluators;

        // Tabela da fase de recurso (chaveada pela própria fase de recurso)
        $config['appealContexts']->{$appeal_phase_id} = ['mainPhaseId' => $main_phase_id];
    }
}

$app->applyHook('component(opportunity-appeal-correction-assignment).config', [&$config]);

$this->jsObject['config']['appealCorrectionAssignment'] = $config;

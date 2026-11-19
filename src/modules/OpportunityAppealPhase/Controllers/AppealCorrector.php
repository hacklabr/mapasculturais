<?php

namespace OpportunityAppealPhase\Controllers;

use MapasCulturais\App;
use MapasCulturais\Controller;
use MapasCulturais\Exceptions\PermissionDenied;
use MapasCulturais\i;
use OpportunityAppealPhase\AppealReview\CorrectorEnvironment;
use OpportunityAppealPhase\Entities\RegistrationAppealReview;

/**
 * Ambiente do corretor designado (issue #19 / F3).
 *
 * GET /appealCorrector/environment/{assignmentId}  -> read-only JSON payload
 * GET /appealCorrector/correction/{assignmentId}   -> correction page (CA-20/#133
 *    rework 2026-11-19, 2ª passada — feedback do teste manual do PR #137:
 *    a página volta a ser "dumb page" para usuário logado — o bloqueio real
 *    vive no endpoint de dados (environment → 403 JSON, AJAX). Sem permissão,
 *    a página carrega e o componente mostra o alerta vermelho de acesso
 *    negado, sem renderizar nenhum dado da correção (comportamento original
 *    do produto). Corretor designado escreve; gestor com @control na fase
 *    principal recebe o payload readOnly; guest cai no 401/redirect do
 *    requireAuthentication)
 * GET /appealCorrector/assignments                  -> active assignments of the logged user
 */
class AppealCorrector extends Controller
{
    function GET_assignments()
    {
        $this->requireAuthentication();

        $app = App::i();

        if (!(bool) env('APPEAL_SCORE_CORRECTION', false)) {
            $this->errorJson(i::__('Correção de notas por recurso desabilitada'), 404);
        }

        $this->json((new CorrectorEnvironment())->listForCorrector($app->user));
    }

    function GET_environment()
    {
        $this->requireAuthentication();

        $app = App::i();

        if (!(bool) env('APPEAL_SCORE_CORRECTION', false)) {
            $this->errorJson(i::__('Correção de notas por recurso desabilitada'), 404);
        }

        $assignment_id = (int) ($this->data['id'] ?? $this->urlData['id'] ?? 0);
        if (!$assignment_id) {
            $this->errorJson(i::__('assignmentId é obrigatório'), 400);
        }

        /** @var RegistrationAppealReview|null $review */
        $review = $app->repo(RegistrationAppealReview::class)->find($assignment_id);
        if (!$review) {
            $this->errorJson(i::__('Designação não encontrada'), 404);
        }

        try {
            $payload = (new CorrectorEnvironment())->build($review);
        } catch (PermissionDenied $e) {
            $this->errorJson(i::__('Sem permissão para acessar o ambiente de correção'), 403);
        }

        $this->json($payload);
    }

    function GET_correction()
    {
        $this->requireAuthentication();

        $app = App::i();

        if (!(bool) env('APPEAL_SCORE_CORRECTION', false)) {
            $this->errorJson(i::__('Correção de notas por recurso desabilitada'), 404);
        }

        $assignment_id = (int) ($this->data['id'] ?? $this->urlData['id'] ?? 0);
        if (!$assignment_id) {
            $app->pass();
        }

        /** @var RegistrationAppealReview|null $review */
        $review = $app->repo(RegistrationAppealReview::class)->find($assignment_id);
        if (!$review) {
            $app->pass();
        }

        // CA-20 (#133 rework, 2026-11-19 — 2ª passada, feedback do teste
        // manual do PR #137): a página volta a ser "dumb page" para usuário
        // logado — SEM guard de visão aqui. O bloqueio real permanece no
        // endpoint de dados (GET_environment → assertViewable → errorJson
        // 403, AJAX): usuário logado sem permissão vê a página carregar com
        // o alerta vermelho de acesso negado do componente e NENHUM dado da
        // correção (comportamento original do produto). Por isso a view
        // recebe apenas o id da designação — nenhum dado da correção é
        // renderizado server-side. Guest já caiu no 401/redirect do
        // requireAuthentication acima.
        $this->render('correction', [
            'assignmentId' => (int) $review->id,
        ]);
    }
}

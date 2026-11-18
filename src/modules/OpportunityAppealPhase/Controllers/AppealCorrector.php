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
 * GET /appealCorrector/correction/{assignmentId}   -> correction page (CA-20/#133:
 *    mesma guarda do environment — feature flag, corretor designado, designação
 *    ativa e dentro do prazo — aplicada antes do render)
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

        // CA-20 (#133): mesma guarda do endpoint do ambiente (corretor
        // designado, designação ativa, janela de prazo) antes do render —
        // usuário errado recebe o mesmo 403; guest já caiu no 401 do
        // requireAuthentication acima.
        try {
            (new CorrectorEnvironment())->assertAssignmentAccessible($review);
        } catch (PermissionDenied $e) {
            $this->errorJson(i::__('Sem permissão para acessar o ambiente de correção'), 403);
        }

        $this->render('correction', [
            'assignmentId' => (int) $review->id,
            'assignment' => $review,
        ]);
    }
}

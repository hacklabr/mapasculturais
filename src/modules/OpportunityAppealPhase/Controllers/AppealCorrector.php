<?php

namespace OpportunityAppealPhase\Controllers;

use MapasCulturais\App;
use MapasCulturais\Controller;
use MapasCulturais\Exceptions\PermissionDenied;
use MapasCulturais\i;
use OpportunityAppealPhase\AppealReview\CorrectorEnvironment;
use OpportunityAppealPhase\Entities\RegistrationAppealReview;

/**
 * Ambiente read-only do corretor designado (PR4.5).
 *
 * GET /appealCorrector/environment/{assignmentId}
 */
class AppealCorrector extends Controller
{
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
}

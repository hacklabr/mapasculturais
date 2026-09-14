<?php

namespace Tests;

use DateTime;
use MapasCulturais\App;
use MapasCulturais\Entities\EvaluationMethodConfiguration;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationEvaluation;
use MapasCulturais\Entities\User;
use OpportunityAppealPhase\Entities\RegistrationAppealReview;
use Psr\Http\Message\ServerRequestInterface;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\ConcurrentEndingAfter;
use Tests\Builders\PhasePeriods\Open;
use Tests\Enums\EvaluationMethods;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RegistrationDirector;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

/**
 * PR6 (#40): API de designação de corretores por slot.
 *
 * Cobertura dos critérios de aceitação:
 * - AC1: criação via API por gestor com @control gera designação com campos do CA-4;
 * - AC2 (CA-3): corretor fora de eligibleCorrectors() é rejeitado com erro claro;
 * - AC3: listagem por inscrição devolve status/prazo/corretor por slot (painel F2);
 * - AC4: permissões (não-gestor → 403; guest → 401) e feature flag.
 */
class AppealAssignmentApiTest extends TestCase
{
    use OpportunityBuilder,
        RegistrationDirector,
        UserDirector,
        RequestFactory;

    private function enableFlag(): void
    {
        $_ENV['APPEAL_SCORE_CORRECTION'] = 'true';
    }

    private function disableFlag(): void
    {
        unset($_ENV['APPEAL_SCORE_CORRECTION']);
        putenv('APPEAL_SCORE_CORRECTION');
    }

    /**
     * Cenário: fase técnica com 2 slots (avaliadores A e B), fase de recurso com
     * Comissão de Recursos (committeeUser) e usuários de controle.
     *
     * @return array{
     *   admin: User, slotOwnerA: User, slotOwnerB: User,
     *   committeeUser: User, outsider: User,
     *   opportunity: Opportunity, registration: Registration,
     *   slotA: RegistrationEvaluation, slotB: RegistrationEvaluation,
     *   appealPhase: Opportunity
     * }
     */
    private function createScenario(): array
    {
        $this->enableFlag();

        $admin = $this->userDirector->createUser('admin');
        $slot_owner_a = $this->userDirector->createUser();
        $slot_owner_b = $this->userDirector->createUser();
        $committee_user = $this->userDirector->createUser();
        $outsider = $this->userDirector->createUser();

        $this->login($admin);

        $this->opportunityBuilder
            ->reset(owner: $admin->profile, owner_entity: $admin->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->addEvaluationPhase(EvaluationMethods::technical)
                ->fillRequiredProperties()
                ->setEvaluationPeriod(new ConcurrentEndingAfter)
                ->save()
                ->addValuer('Comissão', 'Avaliador A', $slot_owner_a->profile)
                    ->done()
                ->addValuer('Comissão', 'Avaliador B', $slot_owner_b->profile)
                    ->done()
                ->done()
            ->save();

        /** @var Opportunity $opportunity */
        $opportunity = $this->opportunityBuilder->getInstance();
        $registration = $this->registrationDirector->createSentRegistration($opportunity, data: []);

        $app = App::i();
        $app->disableAccessControl();

        $slot_a = new RegistrationEvaluation();
        $slot_a->registration = $registration;
        $slot_a->user = $slot_owner_a;
        $slot_a->status = RegistrationEvaluation::STATUS_EVALUATED;
        $slot_a->save(true);

        $slot_b = new RegistrationEvaluation();
        $slot_b->registration = $registration;
        $slot_b->user = $slot_owner_b;
        $slot_b->status = RegistrationEvaluation::STATUS_EVALUATED;
        $slot_b->save(true);

        $appeal_phase_class = $opportunity->getSpecializedClassName();
        /** @var Opportunity $appeal_phase */
        $appeal_phase = new $appeal_phase_class();
        $appeal_phase->parent = $opportunity;
        $appeal_phase->status = Opportunity::STATUS_APPEAL_PHASE;
        $appeal_phase->name = 'Fase de recurso técnica';
        $appeal_phase->ownerEntity = $opportunity->ownerEntity;
        $appeal_phase->owner = $opportunity->owner;
        $appeal_phase->registrationCategories = $opportunity->registrationCategories;
        $appeal_phase->registrationRanges = $opportunity->registrationRanges;
        $appeal_phase->registrationProponentTypes = $opportunity->registrationProponentTypes;
        $appeal_phase->isDataCollection = true;
        $appeal_phase->isAppealPhase = true;
        $appeal_phase->registrationFrom = new DateTime('-1 day');
        $appeal_phase->registrationTo = new DateTime('+1 day');
        $appeal_phase->save(true);

        $opportunity->appealPhase = $appeal_phase;
        $opportunity->save(true);

        $appeal_emc = new EvaluationMethodConfiguration();
        $appeal_emc->opportunity = $appeal_phase;
        $appeal_emc->type = 'continuous';
        $appeal_emc->publishEvaluationDetails = true;
        $appeal_emc->save(true);

        $appeal_phase->evaluationMethodConfiguration = $appeal_emc;
        $appeal_phase->save(true);

        $appeal_emc->createAgentRelation($committee_user->profile, 'committee 1', true)->save(true);

        $app->enableAccessControl();

        return [
            'admin' => $admin,
            'slotOwnerA' => $slot_owner_a,
            'slotOwnerB' => $slot_owner_b,
            'committeeUser' => $committee_user,
            'outsider' => $outsider,
            'opportunity' => $opportunity,
            'registration' => $registration,
            'slotA' => $slot_a,
            'slotB' => $slot_b,
            'appealPhase' => $appeal_phase,
        ];
    }

    /**
     * Executa uma requisição in-process e devolve status + JSON do corpo.
     *
     * @return array{status: int, json: mixed}
     */
    private function dispatch(ServerRequestInterface $request): array
    {
        $app = App::i();
        $app->reset();
        $app->run($request, false);

        return [
            'status' => $app->response->getStatusCode(),
            'json' => json_decode((string) $app->response->getBody(), true),
        ];
    }

    /**
     * @return array{status: int, json: mixed}
     */
    private function postAssignment(array $payload): array
    {
        return $this->dispatch(
            $this->requestFactory->POST('registrationappealreview', 'index', [], $payload)
        );
    }

    /**
     * @return array{status: int, json: mixed}
     */
    private function findAssignments(Registration $registration, string $select = 'id,status,correctionType,startsAt,endsAt,sentTimestamp'): array
    {
        return $this->dispatch(
            $this->requestFactory->GET(
                'api/registrationappealreview',
                'find',
                [],
                [
                    'registration' => 'EQ(' . $registration->id . ')',
                    '@select' => $select,
                ],
                ajax: true
            )
        );
    }

    private function countActiveReviewsForSlot(RegistrationEvaluation $slot): int
    {
        $app = App::i();

        return count($app->repo(RegistrationAppealReview::class)->findBy([
            'originalEvaluation' => $slot->id,
            'status' => RegistrationAppealReview::getActiveStatuses(),
        ]));
    }

    function testManagerCanDesignateCommitteeMemberWithCa4Fields(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $response = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
            'correctionType' => RegistrationAppealReview::CORRECTION_TYPE_OFFICIAL,
            'releasedScope' => json_encode(['criteria' => ['c-1'], 'showAppealOpinion' => true]),
            'startsAt' => '2026-09-01 09:00',
            'endsAt' => '2026-09-10 18:00',
        ]);

        $this->assertEquals(200, $response['status'], 'Gestor com @control deve criar designação: ' . json_encode($response['json']));
        $this->assertNotEmpty($response['json']['id'], 'Resposta deve devolver o ID da designação criada.');

        /** @var RegistrationAppealReview $review */
        $review = App::i()->repo(RegistrationAppealReview::class)->find($response['json']['id']);
        $this->assertNotNull($review, 'Designação deve estar persistida.');

        // Campos do CA-4
        $this->assertEquals($scenario['slotA']->id, $review->originalEvaluation->id);
        $this->assertEquals($scenario['committeeUser']->id, $review->correctorUser->id);
        $this->assertEquals(RegistrationAppealReview::CORRECTION_TYPE_OFFICIAL, $review->correctionType);
        $this->assertEquals(['criteria' => ['c-1'], 'showAppealOpinion' => true], (array) $review->releasedScope);
        $this->assertEquals('2026-09-01 09:00:00', $review->startsAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-09-10 18:00:00', $review->endsAt->format('Y-m-d H:i:s'));

        // Campos derivados do slot no servidor
        $this->assertEquals($scenario['registration']->id, $review->registration->id);
        $this->assertEquals($scenario['appealPhase']->id, $review->appealPhase->id);
        $this->assertEquals($scenario['slotOwnerA']->id, $review->slotOwnerUser->id);
        $this->assertEquals(RegistrationAppealReview::STATUS_DESIGNATED, $review->status);
        $this->assertNull($review->sentTimestamp, 'Designação recém-criada não tem envio.');
    }

    function testSlotOwnerIsEligibleCorrector(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $response = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['slotOwnerA']->id,
            'correctionType' => RegistrationAppealReview::CORRECTION_TYPE_RECORD,
        ]);

        $this->assertEquals(200, $response['status'], 'O dono do slot é corretor elegível (CA-3a): ' . json_encode($response['json']));
        $this->assertEquals(1, $this->countActiveReviewsForSlot($scenario['slotA']));
    }

    function testEvaluatorFromAnotherSlotIsRejected(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        // R17: avaliador de OUTRO slot da mesma inscrição, fora da Comissão de Recursos.
        $response = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['slotOwnerB']->id,
        ]);

        $this->assertEquals(400, $response['status'], 'Avaliador de outro slot deve ser rejeitado (CA-3).');
        $this->assertTrue($response['json']['error'] ?? false, 'Resposta de erro deve seguir o contrato errorJson.');
        $this->assertStringContainsStringIgnoringCase('eleg', (string) $response['json']['data'], 'Mensagem deve explicar a inelegibilidade (CA-3).');
        $this->assertEquals(0, $this->countActiveReviewsForSlot($scenario['slotA']), 'Nenhuma designação deve ser persistida.');
    }

    function testNonManagerForbidden(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['outsider']);

        $response = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);

        $this->assertEquals(403, $response['status'], 'Usuário sem @control na oportunidade recebe 403.');
        $this->assertEquals(0, $this->countActiveReviewsForSlot($scenario['slotA']), 'Nenhuma designação deve ser persistida.');
    }

    function testNonAdminManagerWithControlCanDesignate(): void
    {
        $scenario = $this->createScenario();

        $manager = $this->userDirector->createUser();
        $app = App::i();
        $app->disableAccessControl();
        $scenario['opportunity']->createAgentRelation($manager->profile, 'group-admin', true)->save(true);
        $app->enableAccessControl();

        // userHasControl consulta as AgentRelations ao vivo; pcache é filtro de
        // listagem da API, não usado neste fluxo.
        $this->login($manager);

        $response = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);

        $this->assertEquals(200, $response['status'], 'Gestor não-admin com relação de controle (group-admin) deve designar: ' . json_encode($response['json']));
    }

    function testGuestUnauthorized(): void
    {
        $scenario = $this->createScenario();
        $this->logout();

        $post = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);

        $this->assertEquals(401, $post['status'], 'Guest recebe 401 na criação.');

        $find = $this->findAssignments($scenario['registration']);
        $this->assertEquals(401, $find['status'], 'Guest recebe 401 na listagem.');
    }

    function testFlagOffReturns404(): void
    {
        $scenario = $this->createScenario();
        $this->disableFlag();
        $this->login($scenario['admin']);

        $response = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);

        $this->assertEquals(404, $response['status'], 'Feature flag desligada deve retornar 404.');
    }

    function testDuplicateActiveDesignationRejected(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $first = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);

        $this->assertEquals(200, $first['status']);

        $second = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['slotOwnerA']->id,
        ]);

        $this->assertEquals(400, $second['status'], 'Segunda designação ativa no mesmo slot deve ser rejeitada com erro claro (índice único).');
        $this->assertEquals(1, $this->countActiveReviewsForSlot($scenario['slotA']));
    }

    function testFindListsAssignmentsPerRegistrationForPanel(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $review_a = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
            'startsAt' => '2026-09-01 09:00',
            'endsAt' => '2026-09-10 18:00',
        ]);
        $this->assertEquals(200, $review_a['status']);

        $review_b = $this->postAssignment([
            'originalEvaluation' => $scenario['slotB']->id,
            'correctorUser' => $scenario['slotOwnerB']->id,
        ]);
        $this->assertEquals(200, $review_b['status']);

        // Designação de OUTRA inscrição: não pode vazar para o painel desta.
        $other_registration = $this->registrationDirector->createSentRegistration($scenario['opportunity'], data: []);
        $app = App::i();
        $app->disableAccessControl();
        $slot_c = new RegistrationEvaluation();
        $slot_c->registration = $other_registration;
        $slot_c->user = $scenario['slotOwnerA'];
        $slot_c->status = RegistrationEvaluation::STATUS_EVALUATED;
        $slot_c->save(true);
        $app->enableAccessControl();

        $review_c = $this->postAssignment([
            'originalEvaluation' => $slot_c->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $this->assertEquals(200, $review_c['status']);

        $response = $this->findAssignments($scenario['registration']);

        $this->assertEquals(200, $response['status'], 'Listagem do painel deve funcionar para gestor com @control.');
        $items = $response['json'];
        $this->assertIsArray($items);
        $this->assertCount(2, $items, 'Listagem escopada à inscrição: 2 slots designados, sem vazar a outra inscrição.');

        $by_id = [];
        foreach ($items as $item) {
            $by_id[$item['id']] = $item;
        }

        $this->assertArrayHasKey($review_a['json']['id'], $by_id);
        $this->assertArrayHasKey($review_b['json']['id'], $by_id);

        $panel_item = $by_id[$review_a['json']['id']];
        $this->assertEquals(RegistrationAppealReview::STATUS_DESIGNATED, $panel_item['status']);
        $this->assertArrayHasKey('startsAt', $panel_item, 'Painel (CA-13) exige prazo.');
        $this->assertArrayHasKey('endsAt', $panel_item);
        $this->assertArrayHasKey('sentTimestamp', $panel_item, 'Painel (CA-13) exige data de envio.');

        // Corretor por slot: resolvido pela entidade (serialização da relação varia na ApiQuery).
        /** @var RegistrationAppealReview $persisted_a */
        $persisted_a = $app->repo(RegistrationAppealReview::class)->find($review_a['json']['id']);
        $this->assertEquals($scenario['committeeUser']->id, $persisted_a->correctorUser->id);
        $this->assertEquals($scenario['slotA']->id, $persisted_a->originalEvaluation->id);
    }

    function testFindForbiddenForNonManager(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['outsider']);

        $response = $this->findAssignments($scenario['registration']);

        $this->assertEquals(403, $response['status'], 'Listagem por inscrição exige @control na oportunidade.');
    }

    function testPatchSubstitutionEnforcesEligibility(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $this->assertEquals(200, $created['status']);
        $review_id = $created['json']['id'];

        // Substituição por corretor inelegível (avaliador de outro slot) → CA-3.
        $ineligible = $this->dispatch(
            $this->requestFactory->PATCH('registrationappealreview', 'single', [$review_id], [
                'correctorUser' => $scenario['slotOwnerB']->id,
            ])
        );
        $this->assertEquals(400, $ineligible['status'], 'Substituição por corretor inelegível deve ser rejeitada (CA-3).');

        // Substituição por corretor elegível (dono do slot) → OK.
        $eligible = $this->dispatch(
            $this->requestFactory->PATCH('registrationappealreview', 'single', [$review_id], [
                'correctorUser' => $scenario['slotOwnerA']->id,
            ])
        );
        $this->assertEquals(200, $eligible['status'], 'Substituição por corretor elegível deve ser aceita: ' . json_encode($eligible['json']));

        /** @var RegistrationAppealReview $persisted */
        $persisted = App::i()->repo(RegistrationAppealReview::class)->find($review_id);
        $this->assertEquals($scenario['slotOwnerA']->id, $persisted->correctorUser->id, 'Substituição deve persistir o novo corretor.');
    }

    function testDeleteForbiddenForOutsider(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $this->assertEquals(200, $created['status']);
        $review_id = $created['json']['id'];

        $this->login($scenario['outsider']);

        $deleted = $this->dispatch(
            $this->requestFactory->DELETE('registrationappealreview', 'single', [$review_id])
        );

        $this->assertEquals(403, $deleted['status'], 'Exclusão de designação exige @control na oportunidade.');
        $this->assertNotNull(App::i()->repo(RegistrationAppealReview::class)->find($review_id), 'Designação não pode ser removida por não-gestor.');
    }
}

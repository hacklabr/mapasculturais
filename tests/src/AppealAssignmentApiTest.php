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
     * Cenário: fase (método parametrizado — technical default) com 2 slots
     * (avaliadores A e B), fase de recurso com Comissão de Recursos
     * (committeeUser) e usuários de controle.
     *
     * @return array{
     *   admin: User, slotOwnerA: User, slotOwnerB: User,
     *   committeeUser: User, outsider: User,
     *   opportunity: Opportunity, registration: Registration,
     *   slotA: RegistrationEvaluation, slotB: RegistrationEvaluation,
     *   appealPhase: Opportunity
     * }
     */
    private function createScenario(EvaluationMethods $method = EvaluationMethods::technical): array
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
            ->addEvaluationPhase($method)
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
        $appeal_phase->name = 'Fase de recurso ' . $method->name;
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

        $starts_at = (new DateTime('+1 day'))->format('Y-m-d') . ' 09:00';
        $ends_at = (new DateTime('+10 days'))->format('Y-m-d') . ' 18:00';

        $response = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
            'correctionType' => RegistrationAppealReview::CORRECTION_TYPE_OFFICIAL,
            'releasedScope' => json_encode([
                'criteria' => ['c-1'],
                'showAppealOpinion' => true,
                'showOriginalValuerName' => false,
            ]),
            'startsAt' => $starts_at,
            'endsAt' => $ends_at,
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
        $this->assertEquals([
            'criteria' => ['c-1'],
            'showAppealOpinion' => true,
            'showOriginalValuerName' => false,
        ], (array) $review->releasedScope);
        $this->assertEquals($starts_at . ':00', $review->startsAt->format('Y-m-d H:i:s'));
        $this->assertEquals($ends_at . ':00', $review->endsAt->format('Y-m-d H:i:s'));

        // Campos derivados do slot no servidor
        $this->assertEquals($scenario['registration']->id, $review->registration->id);
        $this->assertEquals($scenario['appealPhase']->id, $review->appealPhase->id);
        $this->assertEquals($scenario['slotOwnerA']->id, $review->slotOwnerUser->id);
        $this->assertEquals(RegistrationAppealReview::STATUS_DESIGNATED, $review->status);
        $this->assertNull($review->sentTimestamp, 'Designação recém-criada não tem envio.');
    }

    function testDesignationWithPastDeadlineRejected(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $response = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
            'endsAt' => (new DateTime('-1 hour'))->format('Y-m-d H:i'),
        ]);

        $this->assertEquals(400, $response['status'], 'Prazo final no passado deixaria o corretor sem acesso desde a criação.');
        $this->assertStringContainsStringIgnoringCase('passado', (string) $response['json']['data']);
        $this->assertEquals(0, $this->countActiveReviewsForSlot($scenario['slotA']));
    }

    function testDesignationWithStartAfterDeadlineRejected(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $response = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
            'startsAt' => (new DateTime('+5 days'))->format('Y-m-d H:i'),
            'endsAt' => (new DateTime('+2 days'))->format('Y-m-d H:i'),
        ]);

        $this->assertEquals(400, $response['status'], 'Início posterior ao prazo final é uma janela vazia.');
        $this->assertEquals(0, $this->countActiveReviewsForSlot($scenario['slotA']));
    }

    function testPatchDeadlineValidatesWindow(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $review_id = $created['json']['id'];

        $past = $this->dispatch(
            $this->requestFactory->PATCH('registrationappealreview', 'single', [$review_id], [
                'endsAt' => (new DateTime('-1 day'))->format('Y-m-d H:i'),
            ])
        );
        $this->assertEquals(400, $past['status'], 'Alterar prazo para o passado deve ser rejeitado.');

        $new_deadline = (new DateTime('+4 days'))->format('Y-m-d H:i');
        $future = $this->dispatch(
            $this->requestFactory->PATCH('registrationappealreview', 'single', [$review_id], [
                'endsAt' => $new_deadline,
            ])
        );
        $this->assertEquals(200, $future['status'], 'Novo prazo futuro deve ser aceito: ' . json_encode($future['json']));
        $this->assertEquals($new_deadline, $this->findReview($review_id)->endsAt->format('Y-m-d H:i'));
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

    /**
     * CA-20 (#133): requireAuthentication na primeira linha do hook de
     * criação de inscrição na fase de recurso — guest recebe 401 explícito
     * (antes dependia do PermissionDenied do save(), que viria como 403).
     */
    function testCreateAppealPhaseRegistrationUnauthorizedForGuest(): void
    {
        $scenario = $this->createScenario();
        $this->logout();

        $response = $this->dispatch(
            $this->requestFactory->POST('opportunity', 'createAppealPhaseRegistration', [$scenario['opportunity']->id], [
                'registration_id' => $scenario['registration']->id,
            ])
        );

        $this->assertEquals(401, $response['status'], 'Guest recebe 401 na criação de inscrição na fase de recurso.');
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
            'startsAt' => (new DateTime('+1 day'))->format('Y-m-d') . ' 09:00',
            'endsAt' => (new DateTime('+10 days'))->format('Y-m-d') . ' 18:00',
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

    /**
     * CA-20 (#133): listagem por oportunidade — uma chamada para o gestor, no
     * lugar de N chamadas (uma por inscrição).
     *
     * @return array{status: int, json: mixed}
     */
    private function findAssignmentsByOpportunity(
        Opportunity $opportunity,
        string $select = 'id,status,startsAt,endsAt,sentTimestamp,registration.number,correctorUser.id',
    ): array
    {
        return $this->dispatch(
            $this->requestFactory->GET(
                'api/registrationappealreview',
                'find',
                [],
                [
                    'opportunity' => 'EQ(' . $opportunity->id . ')',
                    '@select' => $select,
                ],
                ajax: true
            )
        );
    }

    function testManagerListsAssignmentsByOpportunity(): void
    {
        $scenario_a = $this->createScenario();
        $scenario_b = $this->createScenario();

        $this->login($scenario_a['admin']);

        $review_a1 = $this->postAssignment([
            'originalEvaluation' => $scenario_a['slotA']->id,
            'correctorUser' => $scenario_a['committeeUser']->id,
            'startsAt' => (new DateTime('+1 day'))->format('Y-m-d') . ' 09:00',
            'endsAt' => (new DateTime('+10 days'))->format('Y-m-d') . ' 18:00',
        ]);
        $this->assertEquals(200, $review_a1['status'], json_encode($review_a1['json']));

        $review_a2 = $this->postAssignment([
            'originalEvaluation' => $scenario_a['slotB']->id,
            'correctorUser' => $scenario_a['slotOwnerB']->id,
        ]);
        $this->assertEquals(200, $review_a2['status']);

        // Designação de OUTRA inscrição da MESMA oportunidade: mesma fase de
        // recurso, deve entrar no escopo.
        $other_registration = $this->registrationDirector->createSentRegistration($scenario_a['opportunity'], data: []);
        $app = App::i();
        $app->disableAccessControl();
        $slot_c = new RegistrationEvaluation();
        $slot_c->registration = $other_registration;
        $slot_c->user = $scenario_a['slotOwnerA'];
        $slot_c->status = RegistrationEvaluation::STATUS_EVALUATED;
        $slot_c->save(true);
        $app->enableAccessControl();

        $review_a3 = $this->postAssignment([
            'originalEvaluation' => $slot_c->id,
            'correctorUser' => $scenario_a['committeeUser']->id,
        ]);
        $this->assertEquals(200, $review_a3['status']);

        // Designação em outra oportunidade (fase de recurso distinta): fora do escopo.
        $this->login($scenario_b['admin']);
        $review_b1 = $this->postAssignment([
            'originalEvaluation' => $scenario_b['slotA']->id,
            'correctorUser' => $scenario_b['committeeUser']->id,
        ]);
        $this->assertEquals(200, $review_b1['status']);

        $this->login($scenario_a['admin']);

        $response = $this->findAssignmentsByOpportunity($scenario_a['opportunity']);

        $this->assertEquals(200, $response['status'], 'Listagem por oportunidade deve funcionar para gestor com @control: ' . json_encode($response['json']));
        $items = $response['json'];
        $this->assertIsArray($items);
        $this->assertCount(3, $items, 'Escopo = designações da oportunidade informada (todas as inscrições), sem vazar de outra oportunidade.');

        $ids = array_map(static fn (array $item) => $item['id'], $items);
        $this->assertContains($review_a1['json']['id'], $ids);
        $this->assertContains($review_a2['json']['id'], $ids);
        $this->assertContains($review_a3['json']['id'], $ids);
        $this->assertNotContains($review_b1['json']['id'], $ids, 'Designação de outra oportunidade não pode vazar na listagem.');

        // Campos que o painel do gestor precisa por designação (CA-20):
        // número da inscrição, corretor, status e prazos — mesma serialização
        // já usada no caminho por inscrição.
        $by_id = [];
        foreach ($items as $item) {
            $by_id[$item['id']] = $item;
        }

        $panel_item = $by_id[$review_a1['json']['id']];
        $this->assertEquals(RegistrationAppealReview::STATUS_DESIGNATED, $panel_item['status']);
        $this->assertSame($scenario_a['registration']->number, $panel_item['registration']['number'] ?? null, 'Painel por oportunidade exige o número da inscrição.');
        $this->assertEquals($scenario_a['committeeUser']->id, (int) ($panel_item['correctorUser'] ?? 0), 'Painel por oportunidade exige o corretor designado.');
        $this->assertArrayHasKey('startsAt', $panel_item, 'Painel exige prazo inicial.');
        $this->assertArrayHasKey('endsAt', $panel_item, 'Painel exige prazo final.');

        // O parâmetro também aceita o ID da fase de recurso: mesmo resultado
        // (âncora de permissão continua sendo a fase principal).
        $by_appeal_phase = $this->findAssignmentsByOpportunity($scenario_a['appealPhase']);
        $this->assertEquals(200, $by_appeal_phase['status']);
        $this->assertCount(3, $by_appeal_phase['json'], 'Parametro opportunity aceita o ID da fase de recurso.');
        $this->assertEqualsCanonicalizing(
            $ids,
            array_map(static fn (array $item) => $item['id'], $by_appeal_phase['json']),
            'Fase principal e fase de recurso da mesma oportunidade listam as mesmas designações.'
        );
    }

    function testFindAssignmentsByOpportunityForbiddenForNonManager(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $this->assertEquals(200, $created['status']);

        $this->login($scenario['outsider']);

        $by_main_phase = $this->findAssignmentsByOpportunity($scenario['opportunity']);
        $this->assertEquals(403, $by_main_phase['status'], 'Listagem por oportunidade exige @control na fase principal.');

        $by_appeal_phase = $this->findAssignmentsByOpportunity($scenario['appealPhase']);
        $this->assertEquals(403, $by_appeal_phase['status'], 'Listagem pela fase de recurso usa a mesma âncora (@control na fase principal).');
    }

    function testFindAssignmentsByOpportunityUnauthorizedForGuest(): void
    {
        $scenario = $this->createScenario();
        $this->logout();

        $response = $this->findAssignmentsByOpportunity($scenario['opportunity']);

        $this->assertEquals(401, $response['status'], 'Guest recebe 401 na listagem por oportunidade.');
    }

    function testFindRejectsRegistrationAndOpportunityTogether(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $response = $this->dispatch(
            $this->requestFactory->GET(
                'api/registrationappealreview',
                'find',
                [],
                [
                    'registration' => 'EQ(' . $scenario['registration']->id . ')',
                    'opportunity' => 'EQ(' . $scenario['opportunity']->id . ')',
                    '@select' => 'id',
                ],
                ajax: true
            )
        );

        $this->assertEquals(400, $response['status'], 'registration e opportunity são mutuamente exclusivos: escopo ambíguo é rejeitado.');
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

    /**
     * Simula o envio definitivo da correção (status SENT + sentTimestamp).
     */
    private function markSent(int $review_id, ?DateTime $ends_at = null): void
    {
        $app = App::i();
        /** @var RegistrationAppealReview $review */
        $review = $app->repo(RegistrationAppealReview::class)->find($review_id);

        $app->disableAccessControl();
        $review->status = RegistrationAppealReview::STATUS_SENT;
        $review->sentTimestamp = new DateTime('-1 hour');
        if ($ends_at) {
            $review->endsAt = $ends_at;
        }
        $review->save(true);
        $app->enableAccessControl();
    }

    /**
     * @return array{status: int, json: mixed}
     */
    private function reopen(int $review_id, array $payload = []): array
    {
        return $this->dispatch(
            $this->requestFactory->POST('registrationappealreview', 'reopen', [$review_id], $payload)
        );
    }

    private function findReview(int $review_id): ?RegistrationAppealReview
    {
        $app = App::i();
        $review = $app->repo(RegistrationAppealReview::class)->find($review_id);

        if ($review) {
            $app->em->refresh($review);
        }

        return $review;
    }

    function testManagerReopensOnlyTheTargetSentSlot(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $review_a = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $review_b = $this->postAssignment([
            'originalEvaluation' => $scenario['slotB']->id,
            'correctorUser' => $scenario['slotOwnerB']->id,
        ]);
        $this->assertEquals(200, $review_a['status']);
        $this->assertEquals(200, $review_b['status']);

        $this->markSent($review_a['json']['id']);
        $this->markSent($review_b['json']['id']);

        $response = $this->reopen($review_a['json']['id']);
        $this->assertEquals(200, $response['status'], 'Gestor deve reabrir a correção enviada do slot: ' . json_encode($response['json']));

        $reopened = $this->findReview($review_a['json']['id']);
        $this->assertEquals(RegistrationAppealReview::STATUS_REOPENED, $reopened->status, 'Slot escolhido volta a ficar editável.');
        $this->assertNotNull($reopened->sentTimestamp, 'Data do último envio é mantida como marca de correção já aplicada.');

        $untouched = $this->findReview($review_b['json']['id']);
        $this->assertEquals(RegistrationAppealReview::STATUS_SENT, $untouched->status, 'Os demais slots não podem ser reabertos junto.');

        $notifications = App::i()->repo('Notification')->findBy(['user' => $scenario['committeeUser']->id]);
        $messages = array_map(static fn ($notification) => (string) $notification->message, $notifications);
        $this->assertNotEmpty(
            array_filter($messages, static fn ($message) => str_contains($message, 'reaberta')),
            'Corretor deve ser notificado da reabertura.'
        );
    }

    function testReopenRejectsDesignationNotSent(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $this->assertEquals(200, $created['status']);

        $response = $this->reopen($created['json']['id']);

        $this->assertEquals(400, $response['status'], 'Somente correção enviada pode ser reaberta.');
        $this->assertEquals(RegistrationAppealReview::STATUS_DESIGNATED, $this->findReview($created['json']['id'])->status);
    }

    function testReopenForbiddenForNonManager(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $this->markSent($created['json']['id']);

        $this->login($scenario['committeeUser']);
        $response = $this->reopen($created['json']['id']);

        $this->assertEquals(403, $response['status'], 'Reabertura exige @control na oportunidade (nem o corretor pode se reabrir).');
        $this->assertEquals(RegistrationAppealReview::STATUS_SENT, $this->findReview($created['json']['id'])->status);
    }

    function testReopenRejectedWhenSlotHasAnotherActiveDesignation(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $sent = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $this->markSent($sent['json']['id']);

        $active = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['slotOwnerA']->id,
        ]);
        $this->assertEquals(200, $active['status']);

        $response = $this->reopen($sent['json']['id']);

        $this->assertEquals(400, $response['status'], 'Reabrir criaria duas designações ativas no mesmo slot.');
        $this->assertEquals(1, $this->countActiveReviewsForSlot($scenario['slotA']));
    }

    function testReopenWithExpiredDeadlineRequiresNewDeadline(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $review_id = $created['json']['id'];
        $this->markSent($review_id, new DateTime('-1 day'));

        $without_deadline = $this->reopen($review_id);
        $this->assertEquals(400, $without_deadline['status'], 'Prazo vencido sem novo prazo deixaria o corretor sem acesso.');
        $this->assertEquals(RegistrationAppealReview::STATUS_SENT, $this->findReview($review_id)->status);

        $new_deadline = (new DateTime('+3 days'))->format('Y-m-d H:i');
        $with_deadline = $this->reopen($review_id, ['endsAt' => $new_deadline]);
        $this->assertEquals(200, $with_deadline['status'], 'Novo prazo futuro permite reabrir: ' . json_encode($with_deadline['json']));

        $reopened = $this->findReview($review_id);
        $this->assertEquals(RegistrationAppealReview::STATUS_REOPENED, $reopened->status);
        $this->assertEquals($new_deadline, $reopened->endsAt->format('Y-m-d H:i'));
    }

    function testReopenWithoutDeadlineClearsExpiredDeadline(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $review_id = $created['json']['id'];
        $this->markSent($review_id, new DateTime('-1 day'));

        $response = $this->reopen($review_id, ['endsAt' => null]);
        $this->assertEquals(200, $response['status'], 'endsAt nulo explícito reabre sem prazo: ' . json_encode($response['json']));

        $reopened = $this->findReview($review_id);
        $this->assertEquals(RegistrationAppealReview::STATUS_REOPENED, $reopened->status);
        $this->assertNull($reopened->endsAt, 'Prazo vencido deve ser removido.');
    }

    function testReopenWithoutEndsAtKeepsCurrentDeadline(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $review_id = $created['json']['id'];
        $deadline = new DateTime('+5 days');
        $this->markSent($review_id, $deadline);

        $this->assertEquals(200, $this->reopen($review_id)['status']);
        $this->assertEquals($deadline->format('Y-m-d H:i'), $this->findReview($review_id)->endsAt->format('Y-m-d H:i'), 'Sem endsAt no payload o prazo vigente é mantido.');
    }

    /**
     * @return array{status: int, json: mixed}
     */
    private function changeDeadline(int $review_id, array $payload): array
    {
        return $this->dispatch(
            $this->requestFactory->POST('registrationappealreview', 'deadline', [$review_id], $payload)
        );
    }

    function testManagerChangesAndRemovesDeadlineOfActiveDesignation(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
            'endsAt' => (new DateTime('+2 days'))->format('Y-m-d H:i'),
        ]);
        $review_id = $created['json']['id'];

        $new_deadline = (new DateTime('+7 days'))->format('Y-m-d H:i');
        $changed = $this->changeDeadline($review_id, ['endsAt' => $new_deadline]);
        $this->assertEquals(200, $changed['status'], 'Gestor altera o prazo da designação ativa: ' . json_encode($changed['json']));
        $this->assertEquals($new_deadline, $this->findReview($review_id)->endsAt->format('Y-m-d H:i'));

        $notifications = App::i()->repo('Notification')->findBy(['user' => $scenario['committeeUser']->id]);
        $messages = array_map(static fn ($notification) => (string) $notification->message, $notifications);
        $this->assertNotEmpty(
            array_filter($messages, static fn ($message) => str_contains($message, 'prazo da correção')),
            'Corretor deve ser notificado da mudança de prazo.'
        );

        $removed = $this->changeDeadline($review_id, ['endsAt' => null]);
        $this->assertEquals(200, $removed['status']);
        $this->assertNull($this->findReview($review_id)->endsAt, 'endsAt null remove o prazo.');
    }

    function testChangeDeadlineRejectsPastDateAndSentDesignation(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $review_id = $created['json']['id'];

        $past = $this->changeDeadline($review_id, ['endsAt' => (new DateTime('-1 day'))->format('Y-m-d H:i')]);
        $this->assertEquals(400, $past['status'], 'Prazo no passado deve ser rejeitado.');
        $this->assertNull($this->findReview($review_id)->endsAt);

        $missing = $this->changeDeadline($review_id, []);
        $this->assertEquals(400, $missing['status'], 'Sem endsAt no payload não há o que alterar.');

        $this->markSent($review_id);
        $sent = $this->changeDeadline($review_id, ['endsAt' => (new DateTime('+3 days'))->format('Y-m-d H:i')]);
        $this->assertEquals(400, $sent['status'], 'Correção enviada não tem prazo editável (usar reabertura).');
    }

    function testChangeDeadlineForbiddenForNonManager(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $review_id = $created['json']['id'];

        $this->login($scenario['committeeUser']);
        $response = $this->changeDeadline($review_id, ['endsAt' => (new DateTime('+30 days'))->format('Y-m-d H:i')]);

        $this->assertEquals(403, $response['status'], 'Corretor não pode alterar o próprio prazo.');
        $this->assertNull($this->findReview($review_id)->endsAt);
    }

    function testDeleteRejectedForAlreadySentCorrection(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $review_id = $created['json']['id'];
        $this->markSent($review_id);
        $this->assertEquals(200, $this->reopen($review_id)['status']);

        $deleted = $this->dispatch(
            $this->requestFactory->DELETE('registrationappealreview', 'single', [$review_id])
        );

        $this->assertEquals(400, $deleted['status'], 'Correção já aplicada (mesmo reaberta) não pode ser apagada.');
        $this->assertNotNull($this->findReview($review_id), 'Registro da nota original deve ser preservado.');
    }

    // ============================================================ //
    // R06 (#134) — CA-21: autoaplicação (POST/PATCH/lote)
    // ============================================================ //

    function testAutoApplyDefaultsToFalseOnCreate(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::simple);
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);

        $this->assertEquals(200, $created['status']);
        $this->assertFalse((bool) $this->findReview($created['json']['id'])->autoApply, 'Default explícito e previsível: desmarcado.');

        $explicit = $this->postAssignment([
            'originalEvaluation' => $scenario['slotB']->id,
            'correctorUser' => $scenario['slotOwnerB']->id,
            'autoApply' => true,
        ]);

        $this->assertEquals(200, $explicit['status']);
        $this->assertTrue((bool) $this->findReview($explicit['json']['id'])->autoApply, 'autoApply=true em método não-técnico é persistido.');
        $this->assertTrue((bool) $explicit['json']['autoApply'], 'Serialização da entidade expõe o flag.');
    }

    function testAutoApplyForcedFalseForRecordType(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::simple);
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
            'correctionType' => RegistrationAppealReview::CORRECTION_TYPE_RECORD,
            'autoApply' => true,
        ]);

        $this->assertEquals(200, $created['status']);
        $this->assertFalse((bool) $this->findReview($created['json']['id'])->autoApply, 'D3: "somente registro" nunca autoaplica (force false, sem erro).');
    }

    function testAutoApplyForcedFalseForTechnicalMethod(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::technical);
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
            'autoApply' => true,
        ]);

        $this->assertEquals(200, $created['status']);
        $this->assertFalse((bool) $this->findReview($created['json']['id'])->autoApply, 'D2: método técnico excluído por construção (force false, sem erro).');
    }

    function testPatchAutoApplyOnActiveDesignation(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::simple);
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $review_id = $created['json']['id'];

        $enabled = $this->dispatch(
            $this->requestFactory->PATCH('registrationappealreview', 'single', [$review_id], ['autoApply' => true])
        );
        $this->assertEquals(200, $enabled['status'], 'PATCH do flag em designação ativa deve ser aceito: ' . json_encode($enabled['json']));
        $this->assertTrue((bool) $this->findReview($review_id)->autoApply);

        $disabled = $this->dispatch(
            $this->requestFactory->PATCH('registrationappealreview', 'single', [$review_id], ['autoApply' => false])
        );
        $this->assertEquals(200, $disabled['status']);
        $this->assertFalse((bool) $this->findReview($review_id)->autoApply);

        // PATCH em método técnico / tipo record também força false (D2/D3).
        $this->login($scenario['admin']);
        $forced = $this->dispatch(
            $this->requestFactory->PATCH('registrationappealreview', 'single', [$review_id], ['autoApply' => true, 'correctionType' => RegistrationAppealReview::CORRECTION_TYPE_RECORD])
        );
        $this->assertEquals(200, $forced['status']);
        $this->assertFalse((bool) $this->findReview($review_id)->autoApply, 'Migração para "somente registro" desliga a autoaplicação.');
    }

    function testPatchAutoApplyRejectedWhenSent(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::simple);
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $review_id = $created['json']['id'];
        $this->markSent($review_id);

        $rejected = $this->dispatch(
            $this->requestFactory->PATCH('registrationappealreview', 'single', [$review_id], ['autoApply' => true])
        );

        $this->assertEquals(400, $rejected['status'], 'Correção enviada já produziu (ou não) o efeito — flag imutável.');
        $this->assertFalse((bool) $this->findReview($review_id)->autoApply);
    }

    function testPatchAutoApplyAfterReopenIsAllowedAgain(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::simple);
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $review_id = $created['json']['id'];
        $this->markSent($review_id);
        $this->assertEquals(200, $this->reopen($review_id)['status']);

        $enabled = $this->dispatch(
            $this->requestFactory->PATCH('registrationappealreview', 'single', [$review_id], ['autoApply' => true])
        );

        $this->assertEquals(200, $enabled['status'], 'Reabertura reabilita a gestão do flag.');
        $this->assertTrue((bool) $this->findReview($review_id)->autoApply);
    }

    /**
     * @return array{status: int, json: mixed}
     */
    private function bulkAutoApply(array $ids): array
    {
        return $this->dispatch(
            $this->requestFactory->POST('registrationappealreview', 'bulkAutoApply', [], ['ids' => $ids])
        );
    }

    function testBulkAutoApplyMarksActiveDesignations(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::simple);
        $this->login($scenario['admin']);

        $review_a = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $review_b = $this->postAssignment([
            'originalEvaluation' => $scenario['slotB']->id,
            'correctorUser' => $scenario['slotOwnerB']->id,
        ]);
        $this->assertEquals(200, $review_a['status']);
        $this->assertEquals(200, $review_b['status']);

        $response = $this->bulkAutoApply([$review_a['json']['id'], $review_b['json']['id']]);

        $this->assertEquals(200, $response['status'], 'Lote do gestor com @control: ' . json_encode($response['json']));
        $this->assertSame(2, $response['json']['counts']['marked']);
        $this->assertSame(0, $response['json']['counts']['skipped']);
        $this->assertTrue((bool) $this->findReview($review_a['json']['id'])->autoApply);
        $this->assertTrue((bool) $this->findReview($review_b['json']['id'])->autoApply);

        // Idempotente: re-marcar entra na contagem sem erro.
        $again = $this->bulkAutoApply([$review_a['json']['id']]);
        $this->assertEquals(200, $again['status']);
        $this->assertSame(1, $again['json']['counts']['marked']);
    }

    function testBulkAutoApplySkipsSentAndNonexistent(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::simple);
        $this->login($scenario['admin']);

        $official = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $sent = $this->postAssignment([
            'originalEvaluation' => $scenario['slotB']->id,
            'correctorUser' => $scenario['slotOwnerB']->id,
        ]);
        $this->assertEquals(200, $official['status']);
        $this->assertEquals(200, $sent['status']);

        $this->markSent($sent['json']['id']);

        $response = $this->bulkAutoApply([$official['json']['id'], $sent['json']['id'], 999999]);

        $this->assertEquals(200, $response['status']);
        $this->assertSame(1, $response['json']['counts']['marked'], 'Somente a designação ativa oficial é marcada.');
        $this->assertSame(2, $response['json']['counts']['skipped']);

        $reasons = [];
        foreach ($response['json']['skipped'] as $item) {
            $reasons[$item['id']] = (string) $item['reason'];
        }
        $this->assertArrayHasKey($sent['json']['id'], $reasons);
        $this->assertStringContainsStringIgnoringCase('enviada', $reasons[$sent['json']['id']]);
        $this->assertArrayHasKey(999999, $reasons);
        $this->assertStringContainsStringIgnoringCase('inexistente', $reasons[999999]);
    }

    function testBulkAutoApplySkipsRecordDesignation(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::simple);
        $this->login($scenario['admin']);

        $record = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
            'correctionType' => RegistrationAppealReview::CORRECTION_TYPE_RECORD,
        ]);
        $this->assertEquals(200, $record['status']);

        $response = $this->bulkAutoApply([$record['json']['id']]);

        $this->assertEquals(200, $response['status']);
        $this->assertSame(0, $response['json']['counts']['marked'], 'Designação record nunca é marcada.');
        $this->assertSame(1, $response['json']['counts']['skipped']);
        $this->assertStringContainsStringIgnoringCase('registro', (string) $response['json']['skipped'][0]['reason']);
        $this->assertFalse((bool) $this->findReview($record['json']['id'])->autoApply);
    }

    function testBulkAutoApplySkipsTechnicalMethod(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::technical);
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $this->assertEquals(200, $created['status']);

        $response = $this->bulkAutoApply([$created['json']['id']]);

        $this->assertEquals(200, $response['status']);
        $this->assertSame(0, $response['json']['counts']['marked'], 'D2: técnico fica de fora do lote.');
        $this->assertSame(1, $response['json']['counts']['skipped']);
        $this->assertStringContainsStringIgnoringCase('técnico', (string) $response['json']['skipped'][0]['reason']);
    }

    function testBulkAutoApplyForbiddenForNonManager(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::simple);
        $this->login($scenario['admin']);

        $created = $this->postAssignment([
            'originalEvaluation' => $scenario['slotA']->id,
            'correctorUser' => $scenario['committeeUser']->id,
        ]);
        $this->assertEquals(200, $created['status']);

        $this->login($scenario['outsider']);

        $response = $this->bulkAutoApply([$created['json']['id']]);

        $this->assertEquals(403, $response['status'], 'Lote exige @control na fase principal.');
        $this->assertFalse((bool) $this->findReview($created['json']['id'])->autoApply);
    }

    function testBulkAutoApplyUnauthorizedForGuest(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::simple);
        $this->logout();

        $response = $this->bulkAutoApply([1]);

        $this->assertEquals(401, $response['status'], 'Guest recebe 401 no lote.');
    }

    function testBulkAutoApplyRequiresIds(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::simple);
        $this->login($scenario['admin']);

        $missing = $this->dispatch(
            $this->requestFactory->POST('registrationappealreview', 'bulkAutoApply', [], [])
        );

        $this->assertEquals(400, $missing['status'], 'Corpo sem ids é erro de contrato.');
    }
}

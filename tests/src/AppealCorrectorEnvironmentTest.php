<?php

namespace Tests;

use DateTime;
use MapasCulturais\App;
use MapasCulturais\Entities\EvaluationMethodConfiguration;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationEvaluation;
use MapasCulturais\Entities\User;
use MapasCulturais\Exceptions\PermissionDenied;
use OpportunityAppealPhase\AppealReview\CorrectorEnvironment;
use OpportunityAppealPhase\Entities\RegistrationAppealReview;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\ConcurrentEndingAfter;
use Tests\Builders\PhasePeriods\Open;
use Tests\Enums\EvaluationMethods;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RegistrationDirector;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

class AppealCorrectorEnvironmentTest extends TestCase
{
    use OpportunityBuilder,
        RegistrationDirector,
        UserDirector,
        RequestFactory;

    private function enableFlag(): void
    {
        $_ENV['APPEAL_SCORE_CORRECTION'] = 'true';
    }

    /**
     * @return array{
     *   corrector: User,
     *   manager: User,
     *   outsider: User,
     *   review: RegistrationAppealReview,
     *   slotA: RegistrationEvaluation,
     *   slotB: RegistrationEvaluation,
     *   appealPhase: Opportunity,
     *   registration: Registration,
     *   appealRegistration: ?Registration
     * }
     */
    private function createScenario(
        bool $show_opinion = false,
        bool $with_appeal_opinion = false,
        ?bool $show_original_valuer_name = null,
    ): array
    {
        $this->enableFlag();

        $admin = $this->userDirector->createUser('admin');
        $slot_owner_a = $this->userDirector->createUser();
        $slot_owner_b = $this->userDirector->createUser();
        $outsider = $this->userDirector->createUser();
        $corrector = $slot_owner_a;
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
                ->config()
                    ->addSection('sec-1', 'Seção 1')
                    ->addCriterion('c-1', 'sec-1', 'Critério 1', 0, 10, 1)
                    ->addCriterion('c-2', 'sec-1', 'Critério 2', 0, 10, 1)
                    ->done()
                ->save()
                ->addValuer('Comissão', 'Avaliador A', $slot_owner_a->profile)
                    ->done()
                ->addValuer('Comissão', 'Avaliador B', $slot_owner_b->profile)
                    ->done()
                ->done()
            ->save();

        $opportunity = $this->opportunityBuilder->getInstance();
        $registration = $this->registrationDirector->createSentRegistration($opportunity, data: []);

        $app = App::i();
        $app->disableAccessControl();

        $slot_a = new RegistrationEvaluation();
        $slot_a->registration = $registration;
        $slot_a->user = $slot_owner_a;
        $slot_a->setEvaluationData((object) ['c-1' => 4, 'c-2' => 6, 'obs' => 'A']);
        $slot_a->status = RegistrationEvaluation::STATUS_SENT;
        $slot_a->sentTimestamp = new DateTime();
        $slot_a->save(true);

        $slot_b = new RegistrationEvaluation();
        $slot_b->registration = $registration;
        $slot_b->user = $slot_owner_b;
        $slot_b->setEvaluationData((object) ['c-1' => 8, 'c-2' => 2, 'obs' => 'B']);
        $slot_b->status = RegistrationEvaluation::STATUS_SENT;
        $slot_b->sentTimestamp = new DateTime();
        $slot_b->save(true);

        $appeal_phase_class = $opportunity->getSpecializedClassName();
        /** @var Opportunity $appeal_phase */
        $appeal_phase = new $appeal_phase_class();
        $appeal_phase->parent = $opportunity;
        $appeal_phase->status = Opportunity::STATUS_APPEAL_PHASE;
        $appeal_phase->name = 'Recurso técnico';
        $appeal_phase->ownerEntity = $opportunity->ownerEntity;
        $appeal_phase->owner = $opportunity->owner;
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
        $appeal_emc->save(true);
        $appeal_phase->evaluationMethodConfiguration = $appeal_emc;
        $appeal_phase->save(true);

        $appeal_registration = null;
        if ($with_appeal_opinion) {
            $conn = $app->em->getConnection();
            $conn->insert('registration', [
                'opportunity_id' => $appeal_phase->id,
                'agent_id' => $registration->owner->id,
                'number' => $registration->number,
                'status' => Registration::STATUS_SENT,
                'create_timestamp' => (new DateTime())->format('Y-m-d H:i:s'),
                'agents_data' => '{}',
                'consolidated_result' => '',
            ]);
            $appeal_registration_id = (int) $conn->fetchOne(
                'SELECT id FROM registration WHERE opportunity_id = :opp AND number = :number ORDER BY id DESC LIMIT 1',
                ['opp' => $appeal_phase->id, 'number' => $registration->number]
            );
            $appeal_registration = $app->repo('Registration')->find($appeal_registration_id);

            $conn->insert('registration_evaluation', [
                'id' => (int) $conn->fetchOne("SELECT nextval('registration_evaluation_id_seq')"),
                'registration_id' => $appeal_registration_id,
                'user_id' => $admin->id,
                'evaluation_data' => json_encode(['status' => '10', 'obs' => 'Deferido']),
                'result' => '10',
                'status' => RegistrationEvaluation::STATUS_SENT,
                'create_timestamp' => (new DateTime())->format('Y-m-d H:i:s'),
                'sent_timestamp' => (new DateTime())->format('Y-m-d H:i:s'),
            ]);
        }

        $review = new RegistrationAppealReview();
        $review->originalEvaluation = $slot_a;
        $review->registration = $registration;
        $review->appealPhase = $appeal_phase;
        $review->slotOwnerUser = $slot_owner_a;
        $review->correctorUser = $corrector;
        $review->status = RegistrationAppealReview::STATUS_DRAFT;
        $review->correctionType = RegistrationAppealReview::CORRECTION_TYPE_OFFICIAL;
        $review->releasedScope = (object) array_filter([
            'criteria' => ['c-1'],
            'showAppealOpinion' => $show_opinion,
            'showOriginalValuerName' => $show_original_valuer_name,
        ], static fn ($value) => $value !== null);
        $review->startsAt = new DateTime('-1 hour');
        $review->endsAt = new DateTime('+1 day');
        $review->originalValue = $slot_a->getEvaluationData();
        $review->originalScore = (float) $slot_a->result;
        $review->correctedValue = (object) ['c-1' => 7, 'c-2' => 9];
        $review->save(true);

        $app->enableAccessControl();

        return [
            'corrector' => $corrector,
            'manager' => $admin,
            'outsider' => $outsider,
            'review' => $review,
            'slotA' => $slot_a,
            'slotB' => $slot_b,
            'appealPhase' => $appeal_phase,
            'registration' => $registration,
            'appealRegistration' => $appeal_registration,
        ];
    }

    function testOnlyActiveCorrectorCanAccess(): void
    {
        $scenario = $this->createScenario();
        $builder = new CorrectorEnvironment();

        $this->login($scenario['corrector']);
        $payload = $builder->build($scenario['review']);
        $this->assertEquals($scenario['review']->id, $payload['assignmentId']);
        $this->assertArrayHasKey('visibleForm', $payload);
        $this->assertEquals($scenario['registration']->number, $payload['visibleForm']['number']);
        $this->assertNotNull($payload['visibleForm']['agentsSummary']);
        // R06 (#133 rework — CA-20): corretor designado, ativo e na janela escreve.
        $this->assertArrayHasKey('readOnly', $payload);
        $this->assertFalse($payload['readOnly'], 'Corretor designado em janela válida recebe readOnly=false.');

        $this->login($scenario['outsider']);
        $this->expectException(PermissionDenied::class);
        $builder->build($scenario['review']);
    }

    function testVisibleFormHonorsReleasedFormFields(): void
    {
        $scenario = $this->createScenario();
        $app = App::i();
        $app->disableAccessControl();

        $scope = (array) ($scenario['review']->releasedScope ?: []);
        $scope['formFields'] = ['spaceSummary'];
        $scenario['review']->releasedScope = (object) $scope;
        $scenario['review']->save(true);
        $app->enableAccessControl();

        $this->login($scenario['corrector']);
        $payload = (new CorrectorEnvironment())->build($scenario['review']);

        $this->assertNull($payload['visibleForm']['agentsSummary']);
        $this->assertNull($payload['visibleForm']['spaceSummary']);
    }

    function testVisibleFormExposesFilledRegistrationFieldValues(): void
    {
        $scenario = $this->createScenario();
        $app = App::i();
        $registration = $scenario['registration'];
        $opportunity = $registration->opportunity;

        $app->disableAccessControl();

        $step = new \MapasCulturais\Entities\RegistrationStep();
        $step->opportunity = $opportunity;
        $step->name = '';
        $step->save(true);

        $field = new \MapasCulturais\Entities\RegistrationFieldConfiguration();
        $field->owner = $opportunity;
        $field->title = 'Campo regressão';
        $field->fieldType = 'text';
        $field->required = false;
        $field->step = $step;
        $field->save(true);

        $field_id = $field->getFieldName();
        $opportunity->registerRegistrationMetadata();
        $registration->$field_id = 'valor-preenchido';
        $registration->save(true);

        $scope = (array) ($scenario['review']->releasedScope ?: []);
        $scope['formFields'] = [$field_id];
        $scenario['review']->releasedScope = (object) $scope;
        $scenario['review']->save(true);
        $app->enableAccessControl();

        $this->login($scenario['corrector']);
        $payload = (new CorrectorEnvironment())->build($scenario['review']);

        $this->assertNull($payload['visibleForm']['agentsSummary']);
        $this->assertCount(1, $payload['visibleForm']['fields']);
        $this->assertEquals($field_id, $payload['visibleForm']['fields'][0]['id']);
        $this->assertEquals('valor-preenchido', $payload['visibleForm']['fields'][0]['value']);
    }

    function testReturnsOnlyDesignatedSlotCriteria(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['corrector']);

        $payload = (new CorrectorEnvironment())->build($scenario['review']);

        $original = (array) $payload['originalEvaluation'];
        $this->assertArrayHasKey('c-1', $original);
        $this->assertArrayNotHasKey('c-2', $original);
        // Parecer (obs) não é critério: permanece liberado mesmo com escopo parcial.
        $this->assertArrayHasKey('obs', $original);
        $this->assertEquals(4.0, (float) $original['c-1']);

        $draft = (array) $payload['draft'];
        $this->assertArrayHasKey('c-1', $draft);
        $this->assertArrayNotHasKey('c-2', $draft);
        $this->assertEquals(7.0, (float) $draft['c-1']);

        $encoded = json_encode($payload);
        $this->assertStringNotContainsString('"c-2":8', $encoded);
        $this->assertStringNotContainsString('"c-2":2', $encoded);
    }

    function testCommitteeOpinionAbsentWhenFlagFalse(): void
    {
        $scenario = $this->createScenario(show_opinion: false, with_appeal_opinion: false);
        $this->login($scenario['corrector']);

        $payload = (new CorrectorEnvironment())->build($scenario['review']);
        $this->assertArrayNotHasKey('committeeOpinion', $payload);
    }

    function testCommitteeOpinionPresentWhenFlagTrue(): void
    {
        $scenario = $this->createScenario(show_opinion: true, with_appeal_opinion: true);
        $this->login($scenario['corrector']);

        $payload = (new CorrectorEnvironment())->build($scenario['review']);
        $this->assertArrayHasKey('committeeOpinion', $payload);
        $this->assertNotNull($payload['committeeOpinion']);
        $this->assertEquals($scenario['appealRegistration']->id, $payload['committeeOpinion']['registrationId']);
        $this->assertEquals('continuous', $payload['committeeOpinion']['method']);
        $this->assertNotEmpty($payload['committeeOpinion']['evaluationsDetails']);
        $this->assertArrayHasKey('obs', $payload['committeeOpinion']['evaluationsDetails'][0]);
        // Mesmo gate do "Exibir detalhamento": corretor sem @control e sem
        // publishValuerNames não recebe nome do parecerista (só #sequencial).
        $this->assertArrayNotHasKey('valuer', $payload['committeeOpinion']['evaluationsDetails'][0]);
    }

    function testOriginalValuerNamePresentByDefault(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['corrector']);

        $payload = (new CorrectorEnvironment())->build($scenario['review']);
        $this->assertArrayHasKey('targetEvaluatorName', $payload);
        $this->assertNotEmpty($payload['targetEvaluatorName']);
    }

    function testOriginalValuerNameAbsentWhenFlagFalse(): void
    {
        $scenario = $this->createScenario(show_original_valuer_name: false);
        $this->login($scenario['corrector']);

        $payload = (new CorrectorEnvironment())->build($scenario['review']);
        $this->assertArrayNotHasKey('targetEvaluatorName', $payload);

        $items = (new CorrectorEnvironment())->listForCorrector($scenario['corrector']);
        $this->assertNotEmpty($items);
        $this->assertNull($items[0]['targetEvaluatorName']);
    }

    function testOriginalValuerNamePresentWhenFlagTrue(): void
    {
        $scenario = $this->createScenario(show_original_valuer_name: true);
        $this->login($scenario['corrector']);

        $payload = (new CorrectorEnvironment())->build($scenario['review']);
        $this->assertArrayHasKey('targetEvaluatorName', $payload);
        $this->assertNotEmpty($payload['targetEvaluatorName']);
    }

    function testEndpointHttpHappyPath(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['corrector']);

        $request = $this->requestFactory->GET(
            'appealCorrector',
            'environment',
            [$scenario['review']->id],
            ajax: true
        );

        $app = App::i();
        $app->reset();
        $app->run($request, false);

        $this->assertEquals(200, $app->response->getStatusCode());
        $body = json_decode((string) $app->response->getBody(), true);
        $this->assertEquals($scenario['review']->id, $body['assignmentId']);
        $this->assertEquals('draft', $body['status']);
        $this->assertEquals('official', $body['correctionType']);
        $this->assertArrayHasKey('c-1', $body['originalEvaluation']);
        $this->assertArrayNotHasKey('c-2', $body['originalEvaluation']);
    }

    function testEndpointForbiddenForOutsider(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['outsider']);

        $request = $this->requestFactory->GET(
            'appealCorrector',
            'environment',
            [$scenario['review']->id],
            ajax: true
        );

        $this->assertStatus403($request);
    }

    /**
     * R06 (#133 rework — CA-20): designação ENVIADA continua VISÍVEL para o
     * corretor (histórico), em modo somente leitura (readOnly=true) — a
     * escrita segue bloqueada (Service::applyCorrection revalida).
     */
    function testSentAssignmentViewableReadOnlyForCorrector(): void
    {
        $scenario = $this->createScenario();
        $app = App::i();
        $app->disableAccessControl();
        $scenario['review']->status = RegistrationAppealReview::STATUS_SENT;
        $scenario['review']->save(true);
        $app->enableAccessControl();

        $this->login($scenario['corrector']);
        $payload = (new CorrectorEnvironment())->build($scenario['review']);

        $this->assertEquals('sent', $payload['status']);
        $this->assertTrue($payload['readOnly'], 'Designação enviada é visualizável apenas como leitura.');
    }

    function testPayloadExposesCriteriaMetadataAndSlotIdentity(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['corrector']);

        $payload = (new CorrectorEnvironment())->build($scenario['review']);

        $this->assertEquals((int) $scenario['slotA']->id, $payload['evaluationId']);
        $this->assertEquals((int) $scenario['registration']->id, $payload['registrationId']);
        $this->assertEquals($scenario['registration']->number, $payload['registrationNumber']);
        $this->assertNotEmpty($payload['opportunityName']);

        $this->assertIsArray($payload['criteria']);
        $this->assertCount(1, $payload['criteria']);

        $criteria_ids = array_map(static fn ($criterion) => $criterion['id'], $payload['criteria']);
        $this->assertEquals(['c-1'], $criteria_ids);

        $criterion = $payload['criteria'][0];
        $this->assertEquals('Critério 1', $criterion['title']);
        $this->assertEquals(10, $criterion['max']);
        $this->assertEquals(1, $criterion['weight']);
        $this->assertEquals('sec-1', $criterion['sectionId']);
        $this->assertEquals('Seção 1', $criterion['sectionName']);
    }

    function testAssignmentsListOnlyOfLoggedCorrectorWithSentAsHistory(): void
    {
        $scenario = $this->createScenario();
        $builder = new CorrectorEnvironment();

        $this->login($scenario['corrector']);
        $items = $builder->listForCorrector($scenario['corrector']);
        $this->assertCount(1, $items);
        $this->assertEquals($scenario['review']->id, $items[0]['assignmentId']);
        $this->assertEquals($scenario['registration']->number, $items[0]['registrationNumber']);
        $this->assertEquals((int) $scenario['registration']->opportunity->id, $items[0]['opportunityId']);
        $this->assertEquals(
            (int) ($scenario['registration']->opportunity->firstPhase->id ?? $scenario['registration']->opportunity->id),
            $items[0]['firstPhaseId']
        );
        $this->assertEquals('draft', $items[0]['status']);
        $this->assertTrue($items[0]['available']);
        $this->assertEquals(CorrectorEnvironment::correctionUrl($scenario['review']), $items[0]['correctionUrl']);

        $this->login($scenario['outsider']);
        $this->assertSame([], $builder->listForCorrector($scenario['outsider']));

        $app = App::i();
        $app->disableAccessControl();
        $scenario['review']->status = RegistrationAppealReview::STATUS_SENT;
        $scenario['review']->sentTimestamp = new DateTime();
        $scenario['review']->save(true);
        $app->enableAccessControl();

        $this->login($scenario['corrector']);
        $items = $builder->listForCorrector($scenario['corrector']);
        $this->assertCount(1, $items);
        $this->assertEquals('sent', $items[0]['status']);
        $this->assertFalse($items[0]['available']);
        $this->assertNotNull($items[0]['sentAt']);
    }

    function testAssignmentsListFlagsExpiredWindowAsUnavailable(): void
    {
        $scenario = $this->createScenario();
        $app = App::i();
        $app->disableAccessControl();
        $scenario['review']->startsAt = new DateTime('-2 days');
        $scenario['review']->endsAt = new DateTime('-1 day');
        $scenario['review']->save(true);
        $app->enableAccessControl();

        $this->login($scenario['corrector']);
        $items = (new CorrectorEnvironment())->listForCorrector($scenario['corrector']);

        $this->assertCount(1, $items);
        $this->assertFalse($items[0]['available']);
    }

    function testAssignmentsEndpointHttpHappyPath(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['corrector']);

        $request = $this->requestFactory->GET(
            'appealCorrector',
            'assignments',
            ajax: true
        );

        $app = App::i();
        $app->reset();
        $app->run($request, false);

        $this->assertEquals(200, $app->response->getStatusCode());
        $body = json_decode((string) $app->response->getBody(), true);
        $this->assertCount(1, $body);
        $this->assertEquals($scenario['review']->id, $body[0]['assignmentId']);
    }

    function testCorrectionPageRendersForCorrector(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['corrector']);

        $request = $this->requestFactory->GET(
            'appealCorrector',
            'correction',
            [$scenario['review']->id],
            ajax: false
        );

        $app = App::i();
        $app->reset();
        $app->run($request, false);

        $this->assertEquals(200, $app->response->getStatusCode());
        $body = (string) $app->response->getBody();
        $this->assertStringContainsString('opportunity-appeal-correction-evaluation', $body);
        $this->assertStringContainsString((string) $scenario['review']->id, $body);
        // Texto com acento chega unicode-escapado em $TEMPLATES; assertar trecho ASCII.
        $this->assertStringContainsString('Campos liberados pelo gestor', $body);
        $this->assertStringContainsString('opportunity-appeal-correction-evaluation__registration', $body);
    }

    /**
     * CA-20 (#133): guard da página de correção — anônimo recebe 401
     * (requisção AJAX; a navegação direta redireciona ao login, 302).
     */
    function testCorrectionPageUnauthorizedForAnonymous(): void
    {
        $scenario = $this->createScenario();
        $this->logout();

        $request = $this->requestFactory->GET(
            'appealCorrector',
            'correction',
            [$scenario['review']->id],
            ajax: true
        );

        $app = App::i();
        $app->reset();
        $app->run($request, false);

        $this->assertEquals(401, $app->response->getStatusCode(), 'Guest recebe 401 na página de correção.');
    }

    /**
     * CA-20 (#133): página de correção para usuário LOGADO sem permissão —
     * comportamento original do produto, restaurado no rework de
     * 2026-11-19 (feedback do teste manual do PR #137): a página CARREGA
     * (200, dumb page) e o componente mostra o alerta vermelho de acesso
     * negado SEM renderizar nenhum dado da correção — o bloqueio real
     * permanece no endpoint de dados (environment → 403 JSON, AJAX).
     */
    function testCorrectionPageRendersNoPermissionAlertForWrongUser(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['outsider']);

        $request = $this->requestFactory->GET(
            'appealCorrector',
            'correction',
            [$scenario['review']->id],
            ajax: false
        );

        $app = App::i();
        $app->reset();
        $app->run($request, false);

        $this->assertEquals(200, $app->response->getStatusCode(), 'Usuário logado sem permissão recebe a página (200) — dumb page original.');
        $body = (string) $app->response->getBody();

        // Página renderizada com o componente e o id da designação.
        $this->assertStringContainsString('opportunity-appeal-correction-evaluation', $body);
        $this->assertStringContainsString((string) $scenario['review']->id, $body);

        // Marcador do alerta de acesso negado: os textos do componente são
        // localizados no HTML ($MAPAS.gettext) — a chave error-forbidden só
        // existe se o componente foi enfileirado pela página.
        $this->assertStringContainsString('error-forbidden', $body, 'Alerta de acesso negado (error-forbidden) deve estar disponível ao componente.');

        // NENHUM dado da correção no HTML server-side: nem o número da
        // inscrição, nem o nome da oportunidade, nem os valores corrigidos.
        $this->assertStringNotContainsString($scenario['registration']->number, $body, 'Número da inscrição não pode vazar no HTML da página.');
        $this->assertStringNotContainsString((string) $scenario['registration']->opportunity->name, $body, 'Nome da oportunidade não pode vazar no HTML da página.');
        $this->assertStringNotContainsString('"c-1":7', $body, 'Valores da correção não podem vazar no HTML da página.');
        $this->assertStringNotContainsString('"error":true', $body, 'Rota de página não pode responder errorJson cru.');

        // O bloqueio real segue no endpoint de dados: environment → 403 JSON.
        $env_request = $this->requestFactory->GET(
            'appealCorrector',
            'environment',
            [$scenario['review']->id],
            ajax: true
        );

        $this->assertStatus403($env_request, 'Endpoint de dados segue bloqueado (403) para usuário sem permissão.');
    }

    function testDesignatedCorrectorCanViewRegistrationForm(): void
    {
        $scenario = $this->createScenario();
        $app = App::i();
        $app->enableAccessControl();

        $registration = $scenario['registration'];
        $outsider = $this->userDirector->createUser();
        /** @var \OpportunityAppealPhase\Module $module */
        $module = $app->modules['OpportunityAppealPhase'];

        $this->assertFalse(
            $module->canDesignatedCorrectorViewRegistration($registration, $outsider),
            'Sem designação, o módulo não libera visão do formulário.'
        );

        $app->disableAccessControl();
        $scenario['review']->correctorUser = $outsider;
        $scenario['review']->save(true);
        $app->enableAccessControl();

        $this->assertTrue(
            $module->canDesignatedCorrectorViewRegistration($registration, $outsider),
            'Com designação, o módulo libera visão do formulário.'
        );
        $this->assertTrue(
            $registration->canUser('view', $outsider),
            'Corretor designado deve ver a inscrição para consultar o formulário.'
        );
        $this->assertTrue(
            $registration->canUser('viewPrivateData', $outsider),
            'Corretor designado deve ver os campos privados do formulário.'
        );
    }

    // ============================================================ //
    // R06 (#133 rework, 2026-11-19) — CA-20: visão dual do ambiente
    // (gestor com @control lê; escrita segue exclusiva do corretor)
    // ============================================================ //

    /**
     * Contrato do acesso dual: gestor com @control VÊ (readOnly), corretor
     * designado VÊ e ESCREVE, usuário sem @control/designação não vê.
     */
    function testDualViewWriteContract(): void
    {
        $scenario = $this->createScenario();
        $builder = new CorrectorEnvironment();
        $review = $scenario['review'];

        $this->login($scenario['corrector']);
        $this->assertTrue($builder->canView($review), 'Corretor designado pode visualizar.');
        $this->assertTrue($builder->canWrite($review), 'Corretor designado em janela válida pode escrever.');

        $this->login($scenario['manager']);
        $this->assertTrue($builder->canView($review), 'Gestor com @control na fase principal pode visualizar (CA-20).');
        $this->assertFalse($builder->canWrite($review), 'Gestor sem designação NÃO pode escrever — escrita é exclusiva do corretor.');
        $builder->assertViewable($review); // guard do payload do ambiente não lança para gestor

        $this->login($scenario['outsider']);
        $this->assertFalse($builder->canView($review), 'Usuário sem designação e sem @control não pode visualizar.');
        $this->assertFalse($builder->canWrite($review));
    }

    /**
     * Gestor com @control abre o payload do ambiente em modo somente
     * leitura (mesma designação ativa que o corretor edita).
     */
    function testManagerWithControlViewsEnvironmentReadOnly(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['manager']);

        $payload = (new CorrectorEnvironment())->build($scenario['review']);
        $this->assertEquals($scenario['review']->id, $payload['assignmentId']);
        $this->assertTrue($payload['readOnly'], 'Gestor sem designação recebe readOnly=true.');
        $this->assertArrayHasKey('visibleForm', $payload);

        $request = $this->requestFactory->GET(
            'appealCorrector',
            'environment',
            [$scenario['review']->id],
            ajax: true
        );

        $app = App::i();
        $app->reset();
        $app->run($request, false);

        $this->assertEquals(200, $app->response->getStatusCode(), 'Gestor com @control acessa o endpoint do ambiente.');
        $body = json_decode((string) $app->response->getBody(), true);
        $this->assertTrue($body['readOnly'], 'Payload do ambiente para gestor carrega readOnly=true.');
    }

    /**
     * Designação ENVIADA permanece visível para o gestor (acompanhamento),
     * em somente leitura — o mesmo histórico que o corretor vê.
     */
    function testManagerViewsSentAssignmentReadOnly(): void
    {
        $scenario = $this->createScenario();
        $app = App::i();
        $app->disableAccessControl();
        $scenario['review']->status = RegistrationAppealReview::STATUS_SENT;
        $scenario['review']->save(true);
        $app->enableAccessControl();

        $this->login($scenario['manager']);
        $payload = (new CorrectorEnvironment())->build($scenario['review']);

        $this->assertEquals('sent', $payload['status']);
        $this->assertTrue($payload['readOnly']);
    }

    /**
     * Corretor fora da janela de prazo continua VENDO o ambiente (leitura),
     * sem escrita — coerente com "designações encerradas permanecem
     * visíveis para corretor (histórico) e gestor".
     */
    function testCorrectorOutOfWindowViewsReadOnly(): void
    {
        $scenario = $this->createScenario();
        $app = App::i();
        $app->disableAccessControl();
        $scenario['review']->startsAt = new DateTime('-2 days');
        $scenario['review']->endsAt = new DateTime('-1 day');
        $scenario['review']->save(true);
        $app->enableAccessControl();

        $this->login($scenario['corrector']);
        $payload = (new CorrectorEnvironment())->build($scenario['review']);

        $this->assertTrue($payload['readOnly'], 'Corretor fora da janela visualiza apenas como leitura.');
    }

    /**
     * A escrita permanece exclusiva do corretor designado: o envio
     * (POST applyAppealCorrection) por gestor sem designação é rejeitado
     * pelo guard do Service (intocado por esta mudança).
     */
    function testManagerCannotSubmitCorrection(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['manager']);

        $request = $this->requestFactory->POST('registrationevaluation', 'applyAppealCorrection', [$scenario['slotA']->id], [
            'evaluationData' => ['c-1' => 9, 'c-2' => 9, 'obs' => 'gestor'],
            'draft' => false,
        ]);

        $this->assertStatus403($request, 'Gestor sem designação não pode enviar correção (escrita é do corretor).');
    }
}

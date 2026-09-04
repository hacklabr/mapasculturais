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
     *   outsider: User,
     *   review: RegistrationAppealReview,
     *   slotA: RegistrationEvaluation,
     *   slotB: RegistrationEvaluation,
     *   appealPhase: Opportunity,
     *   registration: Registration,
     *   appealRegistration: ?Registration
     * }
     */
    private function createScenario(bool $show_opinion = false, bool $with_appeal_opinion = false): array
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
        $review->releasedScope = (object) [
            'criteria' => ['c-1'],
            'showAppealOpinion' => $show_opinion,
        ];
        $review->startsAt = new DateTime('-1 hour');
        $review->endsAt = new DateTime('+1 day');
        $review->originalValue = $slot_a->getEvaluationData();
        $review->originalScore = (float) $slot_a->result;
        $review->correctedValue = (object) ['c-1' => 7, 'c-2' => 9];
        $review->save(true);

        $app->enableAccessControl();

        return [
            'corrector' => $corrector,
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

        $this->login($scenario['outsider']);
        $this->expectException(PermissionDenied::class);
        $builder->build($scenario['review']);
    }

    function testReturnsOnlyDesignatedSlotCriteria(): void
    {
        $scenario = $this->createScenario();
        $this->login($scenario['corrector']);

        $payload = (new CorrectorEnvironment())->build($scenario['review']);

        $original = (array) $payload['originalEvaluation'];
        $this->assertArrayHasKey('c-1', $original);
        $this->assertArrayNotHasKey('c-2', $original);
        $this->assertArrayNotHasKey('obs', $original);
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
        $this->assertNotEmpty($payload['committeeOpinion']['evaluations']);
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

    function testSentAssignmentRejected(): void
    {
        $scenario = $this->createScenario();
        $app = App::i();
        $app->disableAccessControl();
        $scenario['review']->status = RegistrationAppealReview::STATUS_SENT;
        $scenario['review']->save(true);
        $app->enableAccessControl();

        $this->login($scenario['corrector']);
        $this->expectException(PermissionDenied::class);
        (new CorrectorEnvironment())->build($scenario['review']);
    }
}

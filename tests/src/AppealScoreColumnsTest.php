<?php

namespace Tests;

use DateTime;
use MapasCulturais\App;
use MapasCulturais\ApiQuery;
use MapasCulturais\Entities\EvaluationMethodConfiguration;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationEvaluation;
use MapasCulturais\Entities\User;
use OpportunityAppealPhase\AppealReview\CorrectorEnvironment;
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
 * F4 (#20) — CA-11: colunas de nota original/corrigida/diferença.
 * R06 (#131) — CA-18: colunas por método de avaliação.
 *
 * - técnico: pipeline numérico preservado (média/nota original, corrigida e
 *   diferença como números);
 * - simplificada/documental/habilitação: as colunas de valor exibem o RÓTULO
 *   do resultado no vocabulário do próprio método (Selecionada/...;
 *   Válida/Inválida; Habilitado/Inabilitado) e a diferença é null (coluna
 *   não registrada/não exportada) — mesma regra na lista, nas avaliações e
 *   nas exportações.
 *
 * Cenários com correção APLICADA (slot A corrigido; slot B intacto;
 * inscrição 2 sem nenhuma correção).
 */
class AppealScoreColumnsTest extends TestCase
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
     * Dados de avaliação por método: slot A (que será corrigido), slot B
     * (intacto) e o evaluationData da correção.
     *
     * @return array{slotA: array, slotB: array, corrected: array}
     */
    private static function evaluationDataFor(string $method): array
    {
        return match ($method) {
            'simple' => [
                'slotA' => ['status' => '3', 'obs' => 'A'],
                'slotB' => ['status' => '10', 'obs' => 'B'],
                'corrected' => ['status' => '10', 'obs' => 'corrigida'],
            ],
            'qualification' => [
                'slotA' => ['cri-1' => ['invalid'], 'obs' => 'A'],
                'slotB' => ['cri-1' => ['valid'], 'obs' => 'B'],
                'corrected' => ['cri-1' => ['valid'], 'obs' => 'corrigida'],
            ],
            'technical' => [
                'slotA' => ['c-1' => 2, 'c-2' => 2, 'obs' => 'A'],
                'slotB' => ['c-1' => 5, 'c-2' => 5, 'obs' => 'B'],
                'corrected' => ['c-1' => 8, 'c-2' => 8, 'obs' => 'corrigida'],
            ],
            default => [
                'slotA' => ['doc_a' => ['evaluation' => 'invalid', 'obs' => '']],
                'slotB' => ['doc_b' => ['evaluation' => 'valid', 'obs' => '']],
                'corrected' => ['doc_a' => ['evaluation' => 'valid', 'obs' => 'corrigida']],
            ],
        };
    }

    /**
     * @return array{admin: User, committeeUser: User, opportunity: Opportunity,
     *   registration1: Registration, registration2: Registration,
     *   slotA: RegistrationEvaluation, slotB: RegistrationEvaluation,
     *   appealPhase: Opportunity, method: string}
     */
    private function createScenario(EvaluationMethods $method): array
    {
        $this->enableFlag();

        $admin = $this->userDirector->createUser('admin');
        $slot_owner_a = $this->userDirector->createUser();
        $slot_owner_b = $this->userDirector->createUser();
        $committee_user = $this->userDirector->createUser();

        $this->login($admin);

        $phase_builder = $this->opportunityBuilder
            ->reset(owner: $admin->profile, owner_entity: $admin->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->addEvaluationPhase($method)
                ->fillRequiredProperties()
                ->setEvaluationPeriod(new ConcurrentEndingAfter)
                ->save();

        // Critérios para os métodos que derivam o resultado da configuração.
        if ($method === EvaluationMethods::technical) {
            $phase_builder = $phase_builder
                ->config()
                    ->addSection('sec-1', 'Seção 1')
                    ->addCriterion('c-1', 'sec-1', 'Critério 1', 0, 10, 1)
                    ->addCriterion('c-2', 'sec-1', 'Critério 2', 0, 10, 1)
                    ->done()
                ->save();
        }

        if ($method === EvaluationMethods::qualification) {
            $phase_builder = $phase_builder
                ->config()
                    ->addSection('sec-1', 'Seção 1')
                    ->addCriterion('cri-1', 'sec-1', 'Critério 1')
                    ->done()
                ->save();
        }

        $phase_builder
            ->addValuer('Comissão', 'Avaliador A', $slot_owner_a->profile)
                ->done()
            ->addValuer('Comissão', 'Avaliador B', $slot_owner_b->profile)
                ->done()
            ->done()
            ->save();

        /** @var Opportunity $opportunity */
        $opportunity = $this->opportunityBuilder->getInstance();

        $registration_1 = $this->registrationDirector->createSentRegistration($opportunity, data: []);
        $registration_2 = $this->registrationDirector->createSentRegistration($opportunity, data: []);

        $data = self::evaluationDataFor($method->name);

        $app = App::i();
        $app->disableAccessControl();

        // Slot A — será corrigido.
        $slot_a = new RegistrationEvaluation();
        $slot_a->registration = $registration_1;
        $slot_a->user = $slot_owner_a;
        $slot_a->status = RegistrationEvaluation::STATUS_SENT;
        $slot_a->setEvaluationData((object) $data['slotA']);
        $slot_a->save(true);

        // Slot B — permanece sem correção.
        $slot_b = new RegistrationEvaluation();
        $slot_b->registration = $registration_1;
        $slot_b->user = $slot_owner_b;
        $slot_b->status = RegistrationEvaluation::STATUS_SENT;
        $slot_b->setEvaluationData((object) $data['slotB']);
        $slot_b->save(true);

        // Inscrição 2: um slot, sem nenhuma correção.
        $slot_c = new RegistrationEvaluation();
        $slot_c->registration = $registration_2;
        $slot_c->user = $slot_owner_b;
        $slot_c->status = RegistrationEvaluation::STATUS_SENT;
        $slot_c->setEvaluationData((object) $data['slotB']);
        $slot_c->save(true);

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
            'committeeUser' => $committee_user,
            'opportunity' => $opportunity,
            'registration1' => $registration_1,
            'registration2' => $registration_2,
            'slotA' => $slot_a,
            'slotB' => $slot_b,
            'appealPhase' => $appeal_phase,
            'method' => $method->name,
        ];
    }

    /**
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
     * Designa o comitê para o slot e aplica a correção (dados por método).
     */
    private function applyRealCorrection(array $scenario): void
    {
        $this->login($scenario['admin']);

        $created = $this->dispatch(
            $this->requestFactory->POST('registrationappealreview', 'index', [], [
                'originalEvaluation' => $scenario['slotA']->id,
                'correctorUser' => $scenario['committeeUser']->id,
            ])
        );
        $this->assertEquals(200, $created['status'], 'Designação: ' . json_encode($created['json']));

        $this->login($scenario['committeeUser']);

        $sent = $this->dispatch(
            $this->requestFactory->POST('registrationevaluation', 'applyAppealCorrection', [$scenario['slotA']->id], [
                'evaluationData' => self::evaluationDataFor($scenario['method'])['corrected'],
                'draft' => false,
            ])
        );
        $this->assertEquals(200, $sent['status'], 'Correção aplicada: ' . json_encode($sent['json']));

        $this->login($scenario['admin']);
        // Sem processPCache: o drain da fila com fase de recurso no cenário
        // esbarra em query pré-existente do OpportunityPhases (EAGER+WITH em
        // OpportunityMeta#owner) — fora do escopo do F4. As consultas abaixo
        // rodam como admin (bypass da subquery de permissão).
    }

    /**
     * @return array<int, array<string, mixed>> linhas indexadas por id
     */
    private function findEvaluationRows(array $scenario, array $ids): array
    {
        $query = new ApiQuery(RegistrationEvaluation::class, [
            '@select' => 'id,originalScore,correctedScore,scoreDifference',
            'id' => 'IN(' . implode(',', $ids) . ')',
        ]);

        $rows = [];
        foreach ($query->find() as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>> linhas indexadas por id
     */
    private function findRegistrationRows(array $ids): array
    {
        $query = new ApiQuery(Registration::class, [
            '@select' => 'id,averageOriginalScore,averageCorrectedScore,scoreDifference',
            'id' => 'IN(' . implode(',', $ids) . ')',
        ]);

        $rows = [];
        foreach ($query->find() as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    // ============================================================ //
    // Técnico — pipeline numérico preservado (byte-compatível)
    // ============================================================ //

    function testTechnicalSlotColumnsRemainNumeric(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::technical);
        $this->applyRealCorrection($scenario);

        $rows = $this->findEvaluationRows($scenario, [$scenario['slotA']->id, $scenario['slotB']->id]);

        // Slot corrigido: 4 → 16 (2 critérios, peso 1)
        $this->assertEquals(4.0, $rows[$scenario['slotA']->id]['originalScore'], 'originalScore numérico = snapshot da primeira correção aplicada');
        $this->assertEquals(16.0, $rows[$scenario['slotA']->id]['correctedScore'], 'correctedScore numérico = nota vigente');
        $this->assertEquals(12.0, $rows[$scenario['slotA']->id]['scoreDifference'], 'scoreDifference = corrigida − original');

        // Slot sem correção: original = vigente, diferença 0
        $this->assertEquals(10.0, $rows[$scenario['slotB']->id]['originalScore']);
        $this->assertEquals(10.0, $rows[$scenario['slotB']->id]['correctedScore']);
        $this->assertEquals(0.0, $rows[$scenario['slotB']->id]['scoreDifference']);
    }

    function testTechnicalRegistrationMeansAndDifference(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::technical);
        $this->applyRealCorrection($scenario);

        $rows = $this->findRegistrationRows([$scenario['registration1']->id, $scenario['registration2']->id]);

        // Inscrição 1: slots (4→16) e (10) → média original 7, corrigida 13, diferença 6
        $reg1 = $rows[$scenario['registration1']->id];
        $this->assertEquals(7.0, $reg1['averageOriginalScore'], 'média original = (4 + 10) / 2');
        $this->assertEquals(13.0, $reg1['averageCorrectedScore'], 'média corrigida = (16 + 10) / 2');
        $this->assertEquals(6.0, $reg1['scoreDifference']);

        // Inscrição 2: sem nenhuma correção → médias iguais, diferença 0
        $reg2 = $rows[$scenario['registration2']->id];
        $this->assertEquals(10.0, $reg2['averageOriginalScore']);
        $this->assertEquals(10.0, $reg2['averageCorrectedScore']);
        $this->assertEquals(0.0, $reg2['scoreDifference'], 'sem correção: diferença 0');
    }

    // ============================================================ //
    // Simplificada — rótulos de status, nunca média de códigos
    // ============================================================ //

    function testSimpleShowsStatusLabelsInsteadOfMeanOfCodes(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::simple);
        $this->applyRealCorrection($scenario);

        // Por slot: rótulo do status, não o código
        $rows = $this->findEvaluationRows($scenario, [$scenario['slotA']->id, $scenario['slotB']->id]);

        $this->assertSame('Não selecionada', $rows[$scenario['slotA']->id]['originalScore'], 'slot corrigido: original = rótulo do status original (3)');
        $this->assertSame('Selecionada', $rows[$scenario['slotA']->id]['correctedScore'], 'slot corrigido: corrigida = rótulo do status corrigido (10)');
        $this->assertNull($rows[$scenario['slotA']->id]['scoreDifference'], 'CA-18: sem diferença fora do técnico');

        $this->assertSame('Selecionada', $rows[$scenario['slotB']->id]['originalScore']);
        $this->assertSame('Selecionada', $rows[$scenario['slotB']->id]['correctedScore']);
        $this->assertNull($rows[$scenario['slotB']->id]['scoreDifference']);

        // Por inscrição: CONSOLIDADO (mínimo) em rótulo — não média aritmética
        // de códigos (slots 3 e 10: média de códigos seria 6.5, sem semântica).
        $rows = $this->findRegistrationRows([$scenario['registration1']->id, $scenario['registration2']->id]);

        $reg1 = $rows[$scenario['registration1']->id];
        $this->assertSame('Não selecionada', $reg1['averageOriginalScore'], 'inscrição: original = consolidado (mín) dos status originais');
        $this->assertSame('Selecionada', $reg1['averageCorrectedScore'], 'inscrição: corrigida = consolidado (mín) dos status vigentes');
        $this->assertNotEquals(6.5, $reg1['averageOriginalScore'], 'não é média de códigos de status');
        $this->assertNull($reg1['scoreDifference']);

        $reg2 = $rows[$scenario['registration2']->id];
        $this->assertSame('Selecionada', $reg2['averageOriginalScore']);
        $this->assertSame('Selecionada', $reg2['averageCorrectedScore']);
        $this->assertNull($reg2['scoreDifference']);
    }

    // ============================================================ //
    // Documental — Válida/Inválida
    // ============================================================ //

    function testDocumentaryShowsValidInvalidLabels(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::documentary);
        $this->applyRealCorrection($scenario);

        $rows = $this->findEvaluationRows($scenario, [$scenario['slotA']->id, $scenario['slotB']->id]);

        // Slot corrigido: inválida (−1) → válida (1), em rótulos
        $this->assertSame('Inválida', $rows[$scenario['slotA']->id]['originalScore'], 'originalScore = rótulo do método (−1 → Inválida)');
        $this->assertSame('Válida', $rows[$scenario['slotA']->id]['correctedScore'], 'correctedScore = rótulo do método (1 → Válida)');
        $this->assertNull($rows[$scenario['slotA']->id]['scoreDifference'], 'CA-18: sem diferença fora do técnico');

        $this->assertSame('Válida', $rows[$scenario['slotB']->id]['originalScore']);
        $this->assertSame('Válida', $rows[$scenario['slotB']->id]['correctedScore']);
        $this->assertNull($rows[$scenario['slotB']->id]['scoreDifference']);
    }

    function testDocumentaryRegistrationShowsConsolidatedLabels(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::documentary);
        $this->applyRealCorrection($scenario);

        $rows = $this->findRegistrationRows([$scenario['registration1']->id, $scenario['registration2']->id]);

        // Inscrição 1: original (−1, 1) → consolidado Inválida; corrigido (1, 1) → Válida
        $reg1 = $rows[$scenario['registration1']->id];
        $this->assertSame('Inválida', $reg1['averageOriginalScore'], 'inscrição: consolidado original em rótulo, não média de 1/−1');
        $this->assertSame('Válida', $reg1['averageCorrectedScore']);
        $this->assertNull($reg1['scoreDifference']);

        $reg2 = $rows[$scenario['registration2']->id];
        $this->assertSame('Válida', $reg2['averageOriginalScore']);
        $this->assertSame('Válida', $reg2['averageCorrectedScore']);
        $this->assertNull($reg2['scoreDifference']);
    }

    // ============================================================ //
    // Habilitação documental — Habilitado/Inabilitado (não "-")
    // ============================================================ //

    function testQualificationShowsHabilitadoInabilitadoLabels(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::qualification);
        $this->applyRealCorrection($scenario);

        $rows = $this->findEvaluationRows($scenario, [$scenario['slotA']->id, $scenario['slotB']->id]);

        // Slot corrigido: inabilitado (invalid) → habilitado (valid), em rótulos.
        // O snapshot numérico não existe para a habilitação (result não numérico):
        // o original é recomputado do originalValue da designação aplicada.
        $this->assertSame('Inabilitado', $rows[$scenario['slotA']->id]['originalScore'], 'original = rótulo recomputado do snapshot originalValue');
        $this->assertSame('Habilitado', $rows[$scenario['slotA']->id]['correctedScore']);
        $this->assertNull($rows[$scenario['slotA']->id]['scoreDifference'], 'CA-18: sem diferença fora do técnico');

        $this->assertSame('Habilitado', $rows[$scenario['slotB']->id]['originalScore']);
        $this->assertSame('Habilitado', $rows[$scenario['slotB']->id]['correctedScore']);
        $this->assertNull($rows[$scenario['slotB']->id]['scoreDifference']);

        // Por inscrição: consolidado em rótulo (antes da R06 as colunas eram
        // sempre vazias — toNumericScore anulava valid/invalid).
        $rows = $this->findRegistrationRows([$scenario['registration1']->id, $scenario['registration2']->id]);

        $reg1 = $rows[$scenario['registration1']->id];
        $this->assertSame('Inabilitado', $reg1['averageOriginalScore'], 'inscrição: consolidado original (algum slot invalid)');
        $this->assertSame('Habilitado', $reg1['averageCorrectedScore'], 'inscrição: consolidado corrigido');
        $this->assertNull($reg1['scoreDifference']);

        $reg2 = $rows[$scenario['registration2']->id];
        $this->assertSame('Habilitado', $reg2['averageOriginalScore']);
        $this->assertSame('Habilitado', $reg2['averageCorrectedScore']);
        $this->assertNull($reg2['scoreDifference']);
    }

    // ============================================================ //
    // API real: diferença ausente fora do técnico
    // ============================================================ //

    function testApiSelectReturnsComputedScoreColumns(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::documentary);
        $this->applyRealCorrection($scenario);

        $evaluation_response = $this->dispatch(
            $this->requestFactory->GET('api/registrationevaluation', 'find', [], [
                '@select' => 'id,originalScore,correctedScore,scoreDifference',
                'id' => "EQ({$scenario['slotA']->id})",
            ], ajax: true)
        );

        $this->assertEquals(200, $evaluation_response['status'], 'API find de avaliações: ' . json_encode($evaluation_response['json']));
        $slot = $evaluation_response['json'][0] ?? null;
        $this->assertNotNull($slot);
        $this->assertSame('Inválida', $slot['originalScore'], 'API: originalScore do slot corrigido em rótulo do método');
        $this->assertSame('Válida', $slot['correctedScore'], 'API: correctedScore do slot corrigido em rótulo do método');
        $this->assertNull($slot['scoreDifference'], 'API: scoreDifference null fora do técnico (coluna não exibida)');

        $registration_response = $this->dispatch(
            $this->requestFactory->GET('api/registration', 'find', [], [
                '@select' => 'id,averageOriginalScore,averageCorrectedScore,scoreDifference',
                'id' => "EQ({$scenario['registration1']->id})",
            ], ajax: true)
        );

        $this->assertEquals(200, $registration_response['status'], 'API find de inscrições: ' . json_encode($registration_response['json']));
        $registration = $registration_response['json'][0] ?? null;
        $this->assertNotNull($registration);
        $this->assertSame('Inválida', $registration['averageOriginalScore'], 'API: consolidado original da inscrição');
        $this->assertSame('Válida', $registration['averageCorrectedScore'], 'API: consolidado corrigido da inscrição');
        $this->assertNull($registration['scoreDifference'], 'API: diferença ausente fora do técnico');
    }

    function testApiKeepsNumericDifferenceForTechnical(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::technical);
        $this->applyRealCorrection($scenario);

        $evaluation_response = $this->dispatch(
            $this->requestFactory->GET('api/registrationevaluation', 'find', [], [
                '@select' => 'id,originalScore,correctedScore,scoreDifference',
                'id' => "EQ({$scenario['slotA']->id})",
            ], ajax: true)
        );

        $this->assertEquals(200, $evaluation_response['status']);
        $slot = $evaluation_response['json'][0] ?? null;
        $this->assertNotNull($slot);
        $this->assertEquals(4.0, $slot['originalScore']);
        $this->assertEquals(16.0, $slot['correctedScore']);
        $this->assertEquals(12.0, $slot['scoreDifference'], 'API: técnico mantém a diferença numérica');
    }

    // ============================================================ //
    // Lista da fase de recurso (linha = inscrição do recurso)
    // ============================================================ //

    /**
     * Lista da fase de recurso: a linha é a inscrição do recurso, mas os
     * resultados vêm da inscrição de mesmo número na fase principal (onde a
     * correção foi aplicada), no formato do método da fase principal.
     */
    function testAppealPhaseRowUsesMainPhaseScores(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::documentary);
        $this->applyRealCorrection($scenario);

        $app = App::i();
        $main_registration = $scenario['registration1'];

        $app->disableAccessControl();
        $appeal_registration = new Registration();
        $appeal_registration->opportunity = $scenario['appealPhase'];
        $appeal_registration->owner = $main_registration->owner;
        $appeal_registration->number = $main_registration->number;
        $appeal_registration->status = Registration::STATUS_APPROVED;
        $appeal_registration->save(true);
        $app->enableAccessControl();

        $rows = $this->findRegistrationRows([$appeal_registration->id, $main_registration->id]);

        $appeal_row = $rows[$appeal_registration->id];
        $this->assertSame('Inválida', $appeal_row['averageOriginalScore'], 'Recurso: consolidado original da fase principal, não o da avaliação do recurso');
        $this->assertSame('Válida', $appeal_row['averageCorrectedScore'], 'Recurso: consolidado corrigido da fase principal');
        $this->assertNull($appeal_row['scoreDifference']);

        $main_row = $rows[$main_registration->id];
        $this->assertSame($main_row['averageOriginalScore'], $appeal_row['averageOriginalScore'], 'Mesmo resultado nas duas listas');
        $this->assertSame($main_row['averageCorrectedScore'], $appeal_row['averageCorrectedScore']);
    }

    /**
     * Reabertura pelo gestor: a correção aplicada segue vigente e o resultado
     * original continua vindo do snapshot (colunas e ambiente do corretor).
     */
    function testReopenedCorrectionKeepsOriginalScoreVisible(): void
    {
        $scenario = $this->createScenario(EvaluationMethods::documentary);
        $this->applyRealCorrection($scenario);

        $app = App::i();
        /** @var RegistrationAppealReview $review */
        $review = $app->repo(RegistrationAppealReview::class)->findOneBy(['originalEvaluation' => $scenario['slotA']->id]);

        $reopened = $this->dispatch(
            $this->requestFactory->POST('registrationappealreview', 'reopen', [$review->id], [])
        );
        $this->assertEquals(200, $reopened['status'], 'Reabertura: ' . json_encode($reopened['json']));

        $rows = $this->findEvaluationRows($scenario, [$scenario['slotA']->id]);
        $row = $rows[$scenario['slotA']->id];

        $this->assertSame('Inválida', $row['originalScore'], 'Reaberta: original continua sendo o snapshot, não o resultado vigente.');
        $this->assertSame('Válida', $row['correctedScore']);
        $this->assertNull($row['scoreDifference'], 'Reaberta: sem diferença fora do técnico');

        $app->em->refresh($review);
        $this->login($scenario['committeeUser']);
        $environment = (new CorrectorEnvironment())->build($review);
        $original = (array) $environment['originalEvaluation'];

        $this->assertEquals('invalid', ((array) $original['doc_a'])['evaluation'], 'Ambiente do corretor mostra a avaliação original, não a já corrigida.');
        $this->assertEquals('valid', ((array) ((array) $environment['draft'])['doc_a'])['evaluation'], 'Rascunho pré-preenchido com a correção enviada.');
    }

    // ============================================================ //
    // Exportações (CA-18: mesma regra nas planilhas)
    // ============================================================ //

    private function createExportJob(string $job_slug, Opportunity $opportunity, string $select): Job
    {
        $app = App::i();

        return $app->enqueueOrReplaceJob($job_slug, [
            'owner' => $opportunity,
            'authenticatedUser' => $app->user,
            'extension' => 'csv',
            'entityClassName' => Registration::class,
            'query' => ['@select' => $select],
        ]);
    }

    /**
     * Planilha de inscrições: rótulos por método e diferença somente no
     * técnico (não registrada => não exportada).
     */
    function testRegistrationsExportHeadersPerMethod(): void
    {
        $app = App::i();
        $job_type = $app->getRegisteredJobType('registrations-spreadsheets');
        $this->assertNotNull($job_type, 'JobType registrations-spreadsheets registrado');

        // Fora do técnico: Resultado original/corrigido, SEM coluna de diferença
        $scenario = $this->createScenario(EvaluationMethods::simple);
        $this->applyRealCorrection($scenario);
        $this->login($scenario['admin']);

        $job = $this->createExportJob('registrations-spreadsheets', $scenario['opportunity'], 'number,averageOriginalScore,averageCorrectedScore,scoreDifference');
        $header = $job_type->getHeader($job);

        $this->assertSame('Resultado original (antes das correções de recurso)', $header['averageOriginalScore']);
        $this->assertSame('Resultado corrigido (vigente)', $header['averageCorrectedScore']);
        $this->assertArrayNotHasKey('scoreDifference', $header, 'CA-18: coluna de diferença não exportada fora do técnico');

        // Técnico: trio de médias preservado
        $scenario = $this->createScenario(EvaluationMethods::technical);
        $this->applyRealCorrection($scenario);
        $this->login($scenario['admin']);

        $job = $this->createExportJob('registrations-spreadsheets', $scenario['opportunity'], 'number,averageOriginalScore,averageCorrectedScore,scoreDifference');
        $header = $job_type->getHeader($job);

        $this->assertSame('Média original (antes das correções de recurso)', $header['averageOriginalScore']);
        $this->assertSame('Média corrigida (vigente)', $header['averageCorrectedScore']);
        $this->assertSame('Diferença (média corrigida - média original)', $header['scoreDifference'], 'técnico mantém a coluna de diferença');
    }

    /**
     * Planilha por slot do método simplificada: colunas original/corrigida em
     * rótulos de status quando existem correções de recurso na fase.
     */
    function testSimpleSlotSpreadsheetIncludesCorrectionColumns(): void
    {
        $app = App::i();
        $job_type = $app->getRegisteredJobType('simple-spreadsheets');
        $this->assertNotNull($job_type, 'JobType simple-spreadsheets registrado');

        $scenario = $this->createScenario(EvaluationMethods::simple);
        $this->applyRealCorrection($scenario);
        $this->login($scenario['admin']);

        $job = $app->enqueueOrReplaceJob('simple-spreadsheets', [
            'owner' => $scenario['opportunity'],
            'authenticatedUser' => $app->user,
            'extension' => 'csv',
            'entityClassName' => RegistrationEvaluation::class,
            'query' => ['@select' => 'result,status,evaluationData'],
        ]);

        $header = $job_type->getHeader($job);
        $sub_header = $header[1];

        $this->assertSame('Resultado original', $sub_header['originalScore'], 'planilha por slot: coluna original em rótulo por método');
        $this->assertSame('Resultado corrigido', $sub_header['correctedScore']);
        $this->assertArrayNotHasKey('scoreDifference', $sub_header, 'planilha por slot: sem diferença fora do técnico');

        // Valores do batch em rótulos do método (não códigos numéricos)
        $batch = $this->invokeProtected($job_type, '_getBatch', $job);

        $by_result = [];
        foreach ($batch as $row) {
            $by_result[$row['originalScore'] . '|' . $row['correctedScore']] = $row;
        }

        $this->assertArrayHasKey('Não selecionada|Selecionada', $by_result, 'slot corrigido exportado com rótulos de status (3 → 10)');
        $this->assertArrayHasKey('Selecionada|Selecionada', $by_result, 'slot sem correção exportado com o resultado vigente');
    }

    /**
     * Sem designações de correção, a planilha por slot não ganha as colunas
     * (contrato de presença das colunas).
     */
    function testSlotSpreadsheetOmitsCorrectionColumnsWithoutReviews(): void
    {
        $app = App::i();
        $job_type = $app->getRegisteredJobType('documentary-spreadsheets');
        $this->assertNotNull($job_type, 'JobType documentary-spreadsheets registrado');

        $scenario = $this->createScenario(EvaluationMethods::documentary);
        $this->login($scenario['admin']);

        $job = $app->enqueueOrReplaceJob('documentary-spreadsheets', [
            'owner' => $scenario['opportunity'],
            'authenticatedUser' => $app->user,
            'extension' => 'csv',
            'entityClassName' => RegistrationEvaluation::class,
            'query' => ['@select' => 'result,status,evaluationData'],
        ]);

        $sub_header = $job_type->getHeader($job)[1];

        $this->assertArrayNotHasKey('originalScore', $sub_header, 'sem correções de recurso: sem colunas de correção');
        $this->assertArrayNotHasKey('correctedScore', $sub_header);
    }

    private function invokeProtected(object $object, string $method_name, ...$arguments): mixed
    {
        $method = new \ReflectionMethod($object, $method_name);
        $method->setAccessible(true);

        return $method->invoke($object, ...$arguments);
    }
}

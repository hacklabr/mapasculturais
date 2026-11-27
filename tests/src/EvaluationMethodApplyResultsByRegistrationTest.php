<?php

namespace Tests;

use MapasCulturais\Entities\Registration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\ConcurrentEndingAfter;
use Tests\Builders\PhasePeriods\Open;
use Tests\Enums\EvaluationMethods;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RegistrationDirector;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

class EvaluationMethodApplyResultsByRegistrationTest extends TestCase
{
    use OpportunityBuilder,
        RegistrationDirector,
        RequestFactory,
        UserDirector;

    public static function evaluationMethods(): array
    {
        return [
            'simplificada' => [EvaluationMethods::simple, 'simple', 'applyEvaluationsSimple'],
            'documental' => [EvaluationMethods::documentary, 'documentary', 'applyEvaluationsDocumentary'],
            'contínua' => [EvaluationMethods::simple, 'continuous', 'applyEvaluationsContinuous'],
            'habilitação documental' => [EvaluationMethods::qualification, 'qualification', 'applyEvaluationsQualification'],
        ];
    }

    #[DataProvider('evaluationMethods')]
    public function testApplyResultsByRegistration(
        EvaluationMethods $evaluation_method,
        string $type,
        string $action
    ): void {
        $admin = $this->userDirector->createUser('admin');
        $this->login($admin);

        $opportunity = $this->opportunityBuilder
            ->reset(owner: $admin->profile, owner_entity: $admin->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->addEvaluationPhase($evaluation_method)
                ->setEvaluationPeriod(new ConcurrentEndingAfter)
                ->save()
                ->done()
            ->getInstance();

        if ($type === 'continuous') {
            $opportunity->evaluationMethodConfiguration->type = $type;
            $opportunity->evaluationMethodConfiguration->save(true);
        }

        $selected = $this->registrationDirector->createSentRegistration($opportunity, data: []);
        $not_selected = $this->registrationDirector->createSentRegistration($opportunity, data: []);

        $request = $this->requestFactory->POST(
            controller_id: 'opportunity',
            action: $action,
            url_params: [$opportunity->id],
            payload: [
                'tabSelected' => 'registration',
                'registrationNumbers' => [$selected->number, 'numero-inexistente'],
                'to' => Registration::STATUS_APPROVED,
            ]
        );

        $this->assertStatus200($request);
        $this->assertSame(Registration::STATUS_APPROVED, $selected->refreshed()->status);
        $this->assertSame(Registration::STATUS_SENT, $not_selected->refreshed()->status);
    }

    public function testApplyResultsValidatesDestinationStatusAgainstPhaseConfig(): void
    {
        $admin = $this->userDirector->createUser('admin');
        $this->login($admin);

        $opportunity = $this->opportunityBuilder
            ->reset(owner: $admin->profile, owner_entity: $admin->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->addEvaluationPhase(EvaluationMethods::qualification)
                ->setEvaluationPeriod(new ConcurrentEndingAfter)
                ->save()
                ->done()
            ->getInstance();

        // configures the phase status labels disabling the "waitlist" status
        $opportunity->statusLabels = [
            (string) Registration::STATUS_DRAFT => 'Rascunho',
            (string) Registration::STATUS_SENT => 'Pendente',
            (string) Registration::STATUS_NOTAPPROVED => 'Inabilitado',
            (string) Registration::STATUS_APPROVED => 'Habilitado',
        ];
        $opportunity->save(true);

        $registration = $this->registrationDirector->createSentRegistration($opportunity, data: []);

        $request = $this->requestFactory->POST(
            controller_id: 'opportunity',
            action: 'applyEvaluationsQualification',
            url_params: [$opportunity->id],
            payload: [
                'tabSelected' => 'registration',
                'registrationNumbers' => [$registration->number],
                'to' => Registration::STATUS_WAITLIST,
            ]
        );

        $this->assertStatus400($request, 'Garantindo que um status desabilitado na configuração de status da fase é rejeitado');
        $this->assertSame(Registration::STATUS_SENT, $registration->refreshed()->status, 'Garantindo que a inscrição permanece com o status anterior após a rejeição');

        $registration->setStatusToNotApproved(true);

        $request = $this->requestFactory->POST(
            controller_id: 'opportunity',
            action: 'applyEvaluationsQualification',
            url_params: [$opportunity->id],
            payload: [
                'tabSelected' => 'registration',
                'registrationNumbers' => [$registration->number],
                'to' => Registration::STATUS_SENT,
            ]
        );

        $this->assertStatus200($request, 'Garantindo que o status Pendente é aceito como status de destino');
        $this->assertSame(Registration::STATUS_SENT, $registration->refreshed()->status, 'Garantindo que a inscrição retornou para Pendente');
    }

    public function testApplyResultsByResultQualification(): void
    {
        $admin = $this->userDirector->createUser('admin');
        $this->login($admin);

        $opportunity = $this->opportunityBuilder
            ->reset(owner: $admin->profile, owner_entity: $admin->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->addEvaluationPhase(EvaluationMethods::qualification)
                ->setEvaluationPeriod(new ConcurrentEndingAfter)
                ->save()
                ->done()
            ->getInstance();

        $valid = $this->registrationDirector->createSentRegistration($opportunity, data: []);
        $invalid = $this->registrationDirector->createSentRegistration($opportunity, data: []);

        $valid->consolidatedResult = 'valid';
        $valid->save(true);
        $invalid->consolidatedResult = 'invalid';
        $invalid->save(true);

        $request = $this->requestFactory->POST(
            controller_id: 'opportunity',
            action: 'applyEvaluationsQualification',
            url_params: [$opportunity->id],
            payload: [
                'tabSelected' => 'result',
                'from' => 'valid',
                'to' => Registration::STATUS_APPROVED,
                'status' => 'pending',
            ]
        );

        $this->assertStatus200($request);
        $this->assertSame(Registration::STATUS_APPROVED, $valid->refreshed()->status, 'Garantindo que a inscrição com resultado consolidado "valid" recebeu o novo status');
        $this->assertSame(Registration::STATUS_SENT, $invalid->refreshed()->status, 'Garantindo que a inscrição com resultado consolidado "invalid" não foi alterada');
    }
}

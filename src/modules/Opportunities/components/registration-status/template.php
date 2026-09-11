<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-icon
    mc-status
    registration-results
    mc-loading
');
?>
<div v-if="!phase.isLastPhase" class="opportunity-phases-timeline__box">
    <label class="semibold opportunity-phases-timeline__label"><?= i::__('Resultado da fase:')?></label>
    <mc-status :status-name="getStatusDisplay(registration)"></mc-status>

    <div v-if="showResults(phase)">
        <div v-if="phase.type == 'qualification'"><?= i::__('Resultado:') ?> <strong>{{registration.consolidatedResult}}</strong></div>
        <div v-if="phase.type == 'technical'"><?= i::__('Pontuação:') ?> <strong>{{formatNote(registration.consolidatedResult)}}</strong></div>
        <div v-if="phase.type == 'documentary'"> 
            <strong v-if="registration.consolidatedResult == '1'">
                <mc-icon name="circle" class="success__color"></mc-icon>
                <?= i::__('Válido') ?>
            </strong>
            <strong v-if="registration.consolidatedResult == '-1'">
                <mc-icon name="circle" class="danger__color"></mc-icon>
                <?= i::__('Inválido') ?>
            </strong>
        </div>

        <div class="opportunity-phases-timeline__buttons">
            <div class="registration-results" v-if="registration.opportunity.isReportingPhase === '1' || registration.opportunity.isFinalReportingPhase === '1'">
                <button class="button button--primary button--sm button--large" @click="redirectToRegistrationForm()"><?php i::_e('Visualizar relatório') ?></button>
            </div>
            <registration-results v-if="showRegistrationResults" :registration="registration" :phase="phase"></registration-results>
        </div>
    </div>
</div>

<div v-if="canShowAppeal && appealPhase && !appealRegistration" class="opportunity-phases-timeline__request-appeal">
    <h5 v-if="!processing" class="bold opportunity-phases-timeline__label--lowercase"><?= i::__('Discorda do resultado?')?></h5>
    <button v-if="!processing" class="button button--primary button--primary-outline" @click="createAppealPhaseRegistration()"><?= i::__('Solicitar recurso') ?></button>

    <div v-if="processing" class="col-12">
        <mc-loading :condition="processing"> <?= i::__('carregando') ?></mc-loading>
    </div>
</div>
<div v-if="appealRegistration?.id" class="opportunity-phases-timeline__request-appeal__box">
    <div class="item__dot-appeal-phase"> <span class="dot"></span> </div>
    <div class="item__content">
        <div class="item__content--title"> <?= i::__('[Recurso]') ?> </div>
        <div v-if="showPhaseDates()" class="item__content--description">
            <h5 class="semibold"><?= i::__('de') ?> <span v-if="dateFrom()">{{dateFrom()}}</span>
            <?= i::__('a') ?> <span v-if="dateTo()">{{dateTo()}}</span>
            <?= i::__('às') ?> <span v-if="hour()">{{hour()}}</span></h5>
        </div>

        <div v-if="appealRegistration.status > 0" class="opportunity-phases-timeline__box">
            <div>
                <label class="semibold opportunity-phases-timeline__label"><?= i::__('Resultado do recurso:')?></label>
                <mc-status :status-name="getStatusDisplay(appealRegistration)"></mc-status>
            </div>
            <registration-results :registration="appealRegistration" :phase="appealRegistration.opportunity.evaluationMethodConfiguration"></registration-results>
        </div>
        <div v-if="appealRegistration && appealRegistration.status == 0" class="opportunity-phases-timeline__request-appeal">
            <h5 class="bold opportunity-phases-timeline__label--lowercase"><?= i::__('Finalize sua inscrição no recurso:')?></h5>
            <button class="button button--primary button--primary" @click="fillFormButton()"><?= i::__('Preencher formulário') ?></button>
        </div>

    </div>
</div>

<!--
    F8 (#21): fluxo do proponente — resultado preliminar → recurso →
    reavaliação → resultado final. Cada passo só renderiza quando publicado
    (gates espelhando o server-side do PR3); nada publicado → seção omitida.
-->
<section
    v-if="showAppealFlow"
    class="registration-status__appeal-flow"
    :aria-label="text('flow title')">

    <h4 class="semibold registration-status__appeal-flow-title"><?= i::__('Fluxo do recurso') ?></h4>

    <ol class="registration-status__appeal-flow-steps">
        <!-- 1. Resultado preliminar -->
        <li v-if="preliminaryPublished" class="registration-status__appeal-flow-step">
            <span class="registration-status__appeal-flow-dot" aria-hidden="true"></span>
            <span class="semibold registration-status__appeal-flow-label"><?= i::__('Resultado preliminar') ?></span>
            <span><?= i::__('Nota preliminar') ?>: <strong>{{ flowScoreOrDash(flowPreliminaryScore) }}</strong></span>
        </li>

        <!-- 2. Recurso (status do próprio proponente; veredito gated server-side) -->
        <li v-if="appealRegistration?.id" class="registration-status__appeal-flow-step">
            <span class="registration-status__appeal-flow-dot" aria-hidden="true"></span>
            <span class="semibold registration-status__appeal-flow-label"><?= i::__('Recurso') ?></span>
            <span :id="'appeal-flow-status-' + registration.id">{{ appealFlowStatusLabel }}</span>
        </li>

        <!-- 3. Reavaliação (nota pós-correção; só com resultado final publicado) -->
        <li v-if="finalPublished && flowHasCorrection" class="registration-status__appeal-flow-step">
            <span class="registration-status__appeal-flow-dot" aria-hidden="true"></span>
            <span class="semibold registration-status__appeal-flow-label"><?= i::__('Reavaliação') ?></span>
            <span><?= i::__('Nota após correção') ?>: <strong>{{ flowScoreOrDash(flowCorrectedScore) }}</strong></span>
        </li>

        <!-- 4. Resultado final -->
        <li v-if="finalPublished" class="registration-status__appeal-flow-step">
            <span class="registration-status__appeal-flow-dot registration-status__appeal-flow-dot--final" aria-hidden="true"></span>
            <span class="semibold registration-status__appeal-flow-label"><?= i::__('Resultado final') ?></span>
            <span><?= i::__('Nota final') ?>: <strong>{{ flowScoreOrDash(flowCorrectedScore) }}</strong></span>
        </li>
    </ol>
</section>

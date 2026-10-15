<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-alert
    mc-icon
    mc-loading
');
?>

<div class="panel--appeal-correction-assignments">
    <mc-loading v-if="loading" :condition="loading"><?= i::__('carregando...') ?></mc-loading>

    <mc-alert v-else-if="error" type="danger"><?= i::__('Não foi possível carregar as correções designadas.') ?></mc-alert>

    <p v-else-if="!assignments.length" class="panel--appeal-correction-assignments__empty">
        <?= i::__('Você não tem correções de avaliação designadas.') ?>
    </p>

    <div v-else class="panel-evaluations__cards">
        <div class="panel--appeal-correction-assignments__card" v-for="assignment in assignments" :key="assignment.assignmentId">
            <div class="panel--appeal-correction-assignments__content">
                <h3 class="bold">{{ assignment.opportunityName }}</h3>

                <div class="panel--appeal-correction-assignments__infos">
                    <span><?= i::__('FASE') ?>: <strong>{{ assignment.phaseName }}</strong></span>
                    <span><?= i::__('INSCRIÇÃO') ?>: <strong>{{ assignment.registrationNumber }}</strong></span>
                    <span><?= i::__('AVALIADOR ORIGINAL') ?>: <strong>{{ assignment.targetEvaluatorName }}</strong></span>
                    <span v-if="assignment.deadline"><?= i::__('PRAZO') ?>: <strong>{{ formatDate(assignment.deadline) }}</strong></span>
                    <span v-if="assignment.sentAt && assignment.status === 'sent'"><?= i::__('ENVIADA EM') ?>: <strong>{{ formatDate(assignment.sentAt) }}</strong></span>
                </div>

                <span class="panel--appeal-correction-assignments__badge" :class="'panel--appeal-correction-assignments__badge--' + assignment.status">
                    {{ statusLabel(assignment.status) }}
                </span>
            </div>

            <a v-if="assignment.available" :href="assignment.correctionUrl" class="button button--primary button--right-icon">
                <?= i::__('Corrigir avaliação') ?>
                <mc-icon name="arrow-right-ios"></mc-icon>
            </a>
            <span v-else-if="assignment.status !== 'sent'" class="panel--appeal-correction-assignments__unavailable">{{ unavailableLabel(assignment) }}</span>
        </div>
    </div>
</div>

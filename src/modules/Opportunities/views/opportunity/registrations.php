<?php
/**
 * @var \MapasCulturais\Themes\BaseV2\Theme $this
 * @var \MapasCulturais\App $app
 */

use MapasCulturais\i;

$this->layout = 'entity';
$this->addOpportunityBreadcramb(i::__('Lista de inscrições'), $entity);
$this->addOpportunityPhasesToJs($entity);
$this->import('
    entity-header
    entity-actions
    mc-breadcrumb
    mc-link
    opportunity-appeal-bulk-auto-apply
    opportunity-appeal-correction-assignment
    opportunity-form-builder
    opportunity-header
    opportunity-registrations-table
    v1-embed-tool
')
?>
<div class="main-app opportunity-registrations">
    <mc-breadcrumb></mc-breadcrumb>
    <opportunity-header :opportunity="entity.parent || entity">
        <template #button>
            <mc-link class="button button--primary-outline" :entity="entity.parent || entity" route="edit" hash="registrations" icon="arrow-left"><?= i::__('Voltar') ?></mc-link>
        </template>
    </opportunity-header>

    <div class="opportunity-registrations__container">
        <opportunity-phase-header :phase="entity"></opportunity-phase-header>

        <opportunity-registrations-table identifier="registrationsList" :phase="entity"></opportunity-registrations-table>
    </div>

    <?php /* F1 (#17): modal de designação de correção (F2), aberto por evento global pela tabela acima; renderizado uma única vez na view (o init.php dele também injeta o contexto de @control/fase de recurso consumido pela coluna) */ ?>
    <opportunity-appeal-correction-assignment></opportunity-appeal-correction-assignment>

    <?php /* R06 (#134) — CA-21 (override 2026-11-18): modal de autoaplicação em lote, aberto por evento global pelo botão "Autoaplicar nas designações de correção" na área de ações da tabela acima (rótulo ajustado no QA 2026-11-23); renderizado uma única vez na view. Reutiliza o config de contexto injetado pelo init do modal de designação (appealContexts/mainPhaseMethods/evaluators). */ ?>
    <opportunity-appeal-bulk-auto-apply></opportunity-appeal-bulk-auto-apply>
</div>
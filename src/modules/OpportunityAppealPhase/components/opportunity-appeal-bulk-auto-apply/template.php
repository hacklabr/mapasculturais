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
    mc-modal
');
?>

<mc-modal ref="modal" classes="opportunity-appeal-bulk-auto-apply" :title="text('title')">
    <template #default="{ close }">
        <div class="opportunity-appeal-bulk-auto-apply__content">
            <mc-alert v-if="!endpointAvailable" type="warning">{{ text('endpoint unavailable') }}</mc-alert>

            <mc-alert v-else-if="!correctionEligible" type="warning">{{ text('eligible context required') }}</mc-alert>

            <mc-loading v-else-if="loading" :condition="loading">{{ text('loading') }}</mc-loading>

            <mc-alert v-else-if="error" type="danger">{{ text('load error') }}</mc-alert>

            <?php /* Relatório do lote (substitui a seleção até fechar/reabrir) */ ?>
            <div v-else-if="report != null" class="opportunity-appeal-bulk-auto-apply__report">
                <div class="opportunity-appeal-bulk-auto-apply__report-summary semibold">
                    <mc-icon name="circle" class="success__color"></mc-icon>
                    {{ reportSummary }}
                </div>

                <template v-if="report.skipped.length">
                    <div class="opportunity-appeal-bulk-auto-apply__report-skipped-title semibold">
                        {{ text('skipped title') }} ({{ report.skipped.length }})
                    </div>

                    <ul class="opportunity-appeal-bulk-auto-apply__report-skipped">
                        <li v-for="item in report.skipped" :key="item.id">
                            {{ skippedLabel(item) }}
                        </li>
                    </ul>

                    <p class="opportunity-appeal-bulk-auto-apply__hint">
                        {{ text('skipped hint') }}
                    </p>
                </template>
            </div>

            <div v-else-if="!designations.length" class="opportunity-appeal-bulk-auto-apply__empty">
                {{ text('empty designations') }}
            </div>

            <template v-else>
                <p class="opportunity-appeal-bulk-auto-apply__hint">
                    {{ text('description') }}
                </p>

                <?php /* Selecionar todos — mesmo padrão do seletor de escopo do modal de designação (fields-visible-evaluators) */ ?>
                <div class="opportunity-appeal-bulk-auto-apply__select-all field">
                    <label class="semibold" for="bulk-apply-all">
                        <input
                            type="checkbox"
                            id="bulk-apply-all"
                            :checked="allSelected"
                            @change="toggleSelectAll()">

                        <span>{{ text('select all') }}</span>
                    </label>

                    <span v-if="selectedDesignations.length > 0" class="opportunity-appeal-bulk-auto-apply__selected-count">
                        {{ text('selected count').replace('%s', selectedDesignations.length) }}
                    </span>
                </div>

                <div class="opportunity-appeal-bulk-auto-apply__list scrollbar">
                    <label
                        v-for="designation in designations"
                        :key="designation.id"
                        class="opportunity-appeal-bulk-auto-apply__item"
                        :class="{ 'semibold': designation.checked }"
                        :for="'bulk-apply-' + designation.id">

                        <input
                            type="checkbox"
                            :id="'bulk-apply-' + designation.id"
                            v-model="designation.checked">

                        <div class="opportunity-appeal-bulk-auto-apply__item-info">
                            <div class="opportunity-appeal-bulk-auto-apply__item-registration">
                                {{ designation.registrationNumber }} — {{ designation.agentName || '—' }}
                            </div>

                            <div class="opportunity-appeal-bulk-auto-apply__item-meta">
                                <span>
                                    <mc-icon name="list"></mc-icon>
                                    {{ text('current result') }}: <strong>{{ designation.resultLabel }}</strong>
                                </span>

                                <span>
                                    <mc-icon name="agent"></mc-icon>
                                    <?= i::__('Corretor') ?>: <strong>{{ designation.correctorName || '—' }}</strong>
                                </span>

                                <span v-if="deadline(designation)">
                                    <mc-icon name="clock"></mc-icon>
                                    <?= i::__('Prazo') ?>: <strong>{{ deadline(designation) }}</strong>
                                </span>

                                <span>
                                    <mc-icon name="circle" :class="statusClass(designation)"></mc-icon>
                                    {{ statusLabel(designation) }}
                                </span>

                                <?php /* R06 (#134) — CA-21: designação já marcada entra no lote como idempotente */ ?>
                                <span v-if="designation.autoApply" class="opportunity-appeal-bulk-auto-apply__item-tag warning__background">
                                    {{ text('already auto apply') }}
                                </span>
                            </div>
                        </div>
                    </label>
                </div>

                <div v-if="!selectedDesignations.length" class="opportunity-appeal-bulk-auto-apply__hint danger__color">
                    {{ text('select at least one') }}
                </div>
            </template>
        </div>
    </template>

    <template #actions="{ close }">
        <button class="button button--text" @click="close()">
            <?= i::__('Cancelar') ?>
        </button>

        <button
            v-if="report == null"
            class="button button--md button--primary"
            :class="{ 'disabled': !canApply }"
            :disabled="!canApply"
            @click="applyBulkAutoApply()">

            {{ applying ? text('applying') : text('apply selected') }} ({{ selectedDesignations.length }})
        </button>

        <button v-else class="button button--md button--primary" @click="close()">
            <?= i::__('Concluir') ?>
        </button>
    </template>
</mc-modal>

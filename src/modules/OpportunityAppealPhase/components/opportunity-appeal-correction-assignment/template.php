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

<mc-modal ref="modal" classes="opportunity-appeal-correction-assignment" :title="text('title')" :subtitle="subtitle">
    <template #default="{ close }">
        <div class="opportunity-appeal-correction-assignment__content">
            <mc-alert v-if="!endpointAvailable" type="warning">{{ text('endpoint unavailable') }}</mc-alert>

            <mc-alert v-if="!correctionEligible" type="warning">{{ text('eligible context required') }}</mc-alert>

            <mc-loading :condition="loading">{{ text('loading') }}</mc-loading>

            <div v-if="!loading && !slots.length" class="opportunity-appeal-correction-assignment__empty">
                {{ text('empty slots') }}
            </div>

            <div v-if="!loading && slots.length" class="opportunity-appeal-correction-assignment__progress semibold">
                <mc-icon name="circle" class="primary__color"></mc-icon>
                {{ progressSummary }}
            </div>

            <div v-if="!loading && slots.length" class="opportunity-appeal-correction-assignment__header">
                <div><?= i::__('Avaliação (slot)') ?></div>
                <div><?= i::__('Corretor designado') ?></div>
                <div><?= i::__('Acompanhamento') ?></div>
            </div>

            <div
                v-for="slot in slots"
                :key="slot.id"
                class="opportunity-appeal-correction-assignment__slot"
                :class="{ 'opportunity-appeal-correction-assignment__slot--disabled': !slotSelectable(slot) }">

                <div class="opportunity-appeal-correction-assignment__slot-score">
                    <label
                        class="opportunity-appeal-correction-assignment__slot-check"
                        :class="{ 'semibold': slot.checked }"
                        :for="'correct-slot-' + slot.id">

                        <input
                            type="checkbox"
                            :id="'correct-slot-' + slot.id"
                            v-model="slot.checked"
                            :disabled="!slotSelectable(slot)">

                        <span>
                            <span class="semibold">{{ text('correct this score') }}</span>
                            <span class="opportunity-appeal-correction-assignment__slot-valuer">
                                {{ slotAgentName(slot) }}
                            </span>
                        </span>
                    </label>

                    <div class="opportunity-appeal-correction-assignment__slot-result">
                        {{ slot.resultString }}
                    </div>

                    <span
                        v-if="activeReviewForSlot(slot)"
                        class="opportunity-appeal-correction-assignment__tag warning__background">
                        {{ text('already designated') }}
                    </span>
                </div>

                <div class="opportunity-appeal-correction-assignment__slot-corrector">
                    <select
                        class="opportunity-appeal-correction-assignment__select"
                        v-model="slot.correctorUserId"
                        :disabled="!slot.checked || !slotSelectable(slot)">

                        <option :value="null" disabled>{{ text('select corrector') }}</option>
                        <option
                            v-for="option in correctorOptions(slot)"
                            :key="option.value"
                            :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>

                    <div
                        v-if="slot.checked && !slot.correctorUserId"
                        class="opportunity-appeal-correction-assignment__slot-hint danger__color">
                        <?= i::__('Selecione o corretor para salvar esta designação') ?>
                    </div>
                </div>

                <div class="opportunity-appeal-correction-assignment__slot-status">
                    <template v-if="reviewForSlot(slot)">
                        <div
                            class="opportunity-appeal-correction-assignment__status semibold"
                            :class="statusClass(reviewForSlot(slot))">

                            <mc-icon name="circle" :class="statusClass(reviewForSlot(slot))"></mc-icon>
                            {{ statusLabel(reviewForSlot(slot)) }}
                        </div>

                        <div v-if="reviewDeadline(reviewForSlot(slot))" class="opportunity-appeal-correction-assignment__status-detail">
                            <mc-icon name="clock"></mc-icon>
                            <span><?= i::__('Prazo') ?>: {{ reviewDeadline(reviewForSlot(slot)) }}</span>
                        </div>

                        <div v-if="reviewSentAt(reviewForSlot(slot))" class="opportunity-appeal-correction-assignment__status-detail">
                            <mc-icon name="send"></mc-icon>
                            <span><?= i::__('Enviada em') ?>: {{ reviewSentAt(reviewForSlot(slot)) }}</span>
                        </div>
                    </template>

                    <span v-else class="opportunity-appeal-correction-assignment__status-detail--empty">
                        {{ text('no designation') }}
                    </span>
                </div>
            </div>
        </div>
    </template>

    <template #actions="{ close }">
        <button class="button button--text" @click="close()">
            <?= i::__('Cancelar') ?>
        </button>

        <button
            class="button button--md button--primary"
            :class="{ 'disabled': !canSave }"
            :disabled="!canSave"
            @click="saveDesignations(close)">

            {{ saving ? text('saving') : text('save') }}
        </button>
    </template>
</mc-modal>

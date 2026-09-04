<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-icon
    mc-loading
    mc-modal
');
?>

<mc-modal ref="modal" classes="opportunity-appeal-correction-assignment" :title="text('title')" :subtitle="subtitle">
    <template #default="{ close }">
        <div class="opportunity-appeal-correction-assignment">
            <div v-if="!endpointAvailable" class="opportunity-appeal-correction-assignment__notice field col-12">
                <mc-icon name="alert"></mc-icon>
                {{ text('endpoint unavailable') }}
            </div>

            <div v-if="!correctionEligible" class="opportunity-appeal-correction-assignment__notice field col-12">
                <mc-icon name="alert"></mc-icon>
                {{ text('eligible context required') }}
            </div>

            <mc-loading :condition="loading"></mc-loading>

            <div v-if="!loading && !slots.length" class="field col-12">
                {{ text('empty slots') }}
            </div>

            <div v-if="!loading && slots.length" class="opportunity-appeal-correction-assignment__progress field col-12 semibold">
                {{ progressSummary }}
            </div>

            <div v-if="!loading && slots.length" class="opportunity-appeal-correction-assignment__header grid-12">
                <div class="col-4"><?= i::__('Avaliação (slot)') ?></div>
                <div class="col-4"><?= i::__('Corretor designado') ?></div>
                <div class="col-4"><?= i::__('Acompanhamento') ?></div>
            </div>

            <div
                v-for="slot in slots"
                :key="slot.id"
                class="opportunity-appeal-correction-assignment__slot grid-12"
                :class="{ 'opportunity-appeal-correction-assignment__slot--disabled': !slotSelectable(slot) }">

                <div class="opportunity-appeal-correction-assignment__slot-score col-4">
                    <label class="field" :for="'correct-slot-' + slot.id" :class="{ 'semibold': slot.checked }">
                        <input
                            type="checkbox"
                            :id="'correct-slot-' + slot.id"
                            v-model="slot.checked"
                            :disabled="!slotSelectable(slot)">

                        <span>
                            {{ text('correct this score') }}
                            <span class="semibold">{{ slotAgentName(slot) }}</span>
                        </span>
                    </label>

                    <div class="opportunity-appeal-correction-assignment__slot-result">
                        {{ slot.resultString }}
                    </div>

                    <div v-if="activeReviewForSlot(slot)" class="opportunity-appeal-correction-assignment__slot-tag">
                        {{ text('already designated') }}
                    </div>
                </div>

                <div class="opportunity-appeal-correction-assignment__slot-corrector col-4">
                    <select
                        class="field"
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
                        class="opportunity-appeal-correction-assignment__slot-hint">
                        <?= i::__('Selecione o corretor para salvar esta designação') ?>
                    </div>
                </div>

                <div class="opportunity-appeal-correction-assignment__slot-status col-4">
                    <template v-if="reviewForSlot(slot)">
                        <span
                            class="opportunity-appeal-correction-assignment__status-label semibold"
                            :class="statusClass(reviewForSlot(slot))">
                            {{ statusLabel(reviewForSlot(slot)) }}
                        </span>

                        <div v-if="reviewDeadline(reviewForSlot(slot))" class="opportunity-appeal-correction-assignment__status-detail">
                            {{ text('deadline') }}: {{ reviewDeadline(reviewForSlot(slot)) }}
                        </div>

                        <div v-if="reviewSentAt(reviewForSlot(slot))" class="opportunity-appeal-correction-assignment__status-detail">
                            {{ text('sent at') }}: {{ reviewSentAt(reviewForSlot(slot)) }}
                        </div>
                    </template>

                    <span v-else class="opportunity-appeal-correction-assignment__status-label--empty">
                        {{ text('no designation') }}
                    </span>
                </div>
            </div>
        </div>
    </template>

    <template #actions="{ close }">
        <button
            class="button button--primary"
            :class="{ 'disabled': !canSave }"
            :disabled="!canSave"
            @click="saveDesignations(close)">

            {{ saving ? text('saving') : text('save') }}
        </button>

        <button class="button button--text" @click="close()">
            <?= i::__('Cancelar') ?>
        </button>
    </template>
</mc-modal>

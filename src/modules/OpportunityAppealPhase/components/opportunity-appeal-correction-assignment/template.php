<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-alert
    mc-confirm-button
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
                :class="{
                    'opportunity-appeal-correction-assignment__slot--disabled': isSlotLocked(slot),
                    'opportunity-appeal-correction-assignment__slot--assigned': !!reviewForSlot(slot),
                }">

                <div class="opportunity-appeal-correction-assignment__slot-score">
                    <!-- Slot designado: leitura estática do dono (sem controle desabilitado) -->
                    <div v-if="reviewForSlot(slot)" class="opportunity-appeal-correction-assignment__slot-owner semibold">
                        <mc-icon name="circle" :class="statusClass(reviewForSlot(slot))"></mc-icon>
                        <span>{{ slotAgentName(slot) }}</span>
                    </div>

                    <label
                        v-else
                        class="opportunity-appeal-correction-assignment__slot-check"
                        :class="{ 'semibold': slot.checked }"
                        :for="'correct-slot-' + slot.id">

                        <input
                            type="checkbox"
                            :id="'correct-slot-' + slot.id"
                            :name="'correct-slot-' + slot.id"
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
                    <!-- Slot designado: corretor como leitura estática com papel -->
                    <div v-if="reviewForSlot(slot)" class="opportunity-appeal-correction-assignment__assigned">
                        <mc-icon name="agent" class="primary__color"></mc-icon>
                        <span class="semibold">{{ reviewCorrectorName(reviewForSlot(slot)) || '—' }}</span>
                        <span class="opportunity-appeal-correction-assignment__tag opportunity-appeal-correction-assignment__tag--role">
                            {{ correctorRoleLabel(slot, reviewForSlot(slot)) }}
                        </span>
                    </div>

                    <template v-else>
                        <select
                            class="opportunity-appeal-correction-assignment__select"
                            :id="'corrector-select-' + slot.id"
                            :name="'corrector-select-' + slot.id"
                            :aria-label="text('select corrector') + ' — ' + slotAgentName(slot)"
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

                        <!-- F6 (#49): seletor de escopo (só para métodos com critérios) -->
                        <div v-if="slot.checked && hasCriteriaSelector" class="opportunity-appeal-correction-assignment__scope">
                            <button
                                type="button"
                                class="button button--sm button--text primary__color"
                                @click="slot.scopeOpen = !slot.scopeOpen">

                                <mc-icon :name="slot.scopeOpen ? 'arrow-up' : 'arrow-down'"></mc-icon>
                                {{ text('criteria scope') }} ({{ slotScopeCount(slot) }}/{{ criteriaCatalog.total }})
                            </button>

                            <div v-if="slot.scopeOpen" class="opportunity-appeal-correction-assignment__scope-list">
                                <div
                                    v-for="section in criteriaCatalog.sections"
                                    :key="section.id || section.name"
                                    class="opportunity-appeal-correction-assignment__scope-section">

                                    <div v-if="section.name" class="semibold">{{ section.name }}</div>

                                    <label
                                        v-for="item in section.items"
                                        :key="item.id"
                                        class="opportunity-appeal-correction-assignment__scope-item"
                                        :for="'scope-' + slot.id + '-' + item.id">

                                        <input
                                            type="checkbox"
                                            :id="'scope-' + slot.id + '-' + item.id"
                                            :name="'scope-' + slot.id + '[]'"
                                            :value="item.id"
                                            v-model="slot.scopeCriteria">

                                        <span>{{ item.label }}</span>
                                    </label>
                                </div>

                                <div v-if="scopeInvalidFrom(slot.scopeCriteria)" class="opportunity-appeal-correction-assignment__slot-hint danger__color">
                                    {{ text('select at least one criterion') }}
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="opportunity-appeal-correction-assignment__slot-status">
                    <template v-if="reviewForSlot(slot)">
                        <div
                            class="opportunity-appeal-correction-assignment__status semibold"
                            :class="statusClass(reviewForSlot(slot))">

                            <mc-icon name="circle" :class="statusClass(reviewForSlot(slot))"></mc-icon>
                            {{ statusLabel(reviewForSlot(slot)) }}
                        </div>

                        <div v-if="reviewCorrectorName(reviewForSlot(slot))" class="opportunity-appeal-correction-assignment__status-detail">
                            <mc-icon name="agent"></mc-icon>
                            <span><?= i::__('Corretor') ?>: {{ reviewCorrectorName(reviewForSlot(slot)) }}</span>
                        </div>

                        <div v-if="reviewDeadline(reviewForSlot(slot))" class="opportunity-appeal-correction-assignment__status-detail">
                            <mc-icon name="clock"></mc-icon>
                            <span><?= i::__('Prazo') ?>: {{ reviewDeadline(reviewForSlot(slot)) }}</span>
                        </div>

                        <div v-if="reviewSentAt(reviewForSlot(slot))" class="opportunity-appeal-correction-assignment__status-detail">
                            <mc-icon name="send"></mc-icon>
                            <span><?= i::__('Enviada em') ?>: {{ reviewSentAt(reviewForSlot(slot)) }}</span>
                        </div>

                        <!-- F6: escopo da designação (todos liberados / X de Y) -->
                        <div class="opportunity-appeal-correction-assignment__status-detail">
                            <mc-icon name="list"></mc-icon>
                            <span>{{ reviewScopeLabel(reviewForSlot(slot)) }}</span>
                        </div>

                        <!-- F5 (#45): gestão de designação ativa (substituir/cancelar) — únicos controles ativos da linha -->
                        <div v-if="canManageReview(reviewForSlot(slot))" class="opportunity-appeal-correction-assignment__actions">
                            <button
                                class="button button--sm button--text primary__color"
                                :class="{ 'disabled': slot.substituting || slot.canceling }"
                                :disabled="slot.substituting || slot.canceling"
                                @click="startSubstitution(slot)">

                                <?= i::__('Substituir corretor') ?>
                            </button>

                            <mc-confirm-button :loading="slot.canceling || false" @confirm="cancelAssignment(slot)">
                                <template #button="modal">
                                    <button
                                        class="button button--sm button--text-danger"
                                        :class="{ 'disabled': slot.substituting || slot.canceling }"
                                        :disabled="slot.substituting || slot.canceling"
                                        @click="modal.open()">

                                        <?= i::__('Cancelar designação') ?>
                                    </button>
                                </template>
                                <template #message>
                                    <?= i::__('Tem certeza que deseja cancelar esta designação? O slot voltará a ficar disponível para nova designação.') ?>
                                </template>
                            </mc-confirm-button>
                        </div>

                        <!-- F5: substituição inline -->
                        <div v-if="slot.substitutionOpen" class="opportunity-appeal-correction-assignment__substitution">
                            <select
                                class="opportunity-appeal-correction-assignment__select"
                                :id="'substitute-corrector-' + slot.id"
                                :name="'substitute-corrector-' + slot.id"
                                :aria-label="text('select substitute') + ' — ' + slotAgentName(slot)"
                                v-model="slot.substituteUserId"
                                :disabled="slot.substituting">

                                <option :value="null" disabled>{{ text('select substitute') }}</option>
                                <option
                                    v-for="option in correctorOptions(slot)"
                                    :key="option.value"
                                    :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>

                            <div v-if="slot.substituteUserId == null" class="opportunity-appeal-correction-assignment__slot-hint danger__color">
                                <?= i::__('Selecione o novo corretor para confirmar') ?>
                            </div>

                            <!-- F6: escopo pré-preenchido com o vigente na substituição -->
                            <div v-if="hasCriteriaSelector" class="opportunity-appeal-correction-assignment__scope">
                                <button
                                    type="button"
                                    class="button button--sm button--text primary__color"
                                    @click="slot.substituteScopeOpen = !slot.substituteScopeOpen">

                                    <mc-icon :name="slot.substituteScopeOpen ? 'arrow-up' : 'arrow-down'"></mc-icon>
                                    {{ text('criteria scope') }} ({{ (slot.substituteScopeCriteria || []).length }}/{{ criteriaCatalog.total }})
                                </button>

                                <div v-if="slot.substituteScopeOpen" class="opportunity-appeal-correction-assignment__scope-list">
                                    <div
                                        v-for="section in criteriaCatalog.sections"
                                        :key="section.id || section.name"
                                        class="opportunity-appeal-correction-assignment__scope-section">

                                        <div v-if="section.name" class="semibold">{{ section.name }}</div>

                                        <label
                                            v-for="item in section.items"
                                            :key="item.id"
                                            class="opportunity-appeal-correction-assignment__scope-item"
                                            :for="'substitute-scope-' + slot.id + '-' + item.id">

                                            <input
                                                type="checkbox"
                                                :id="'substitute-scope-' + slot.id + '-' + item.id"
                                                :name="'substitute-scope-' + slot.id + '[]'"
                                                :value="item.id"
                                                v-model="slot.substituteScopeCriteria">

                                            <span>{{ item.label }}</span>
                                        </label>
                                    </div>

                                    <div v-if="scopeInvalidFrom(slot.substituteScopeCriteria)" class="opportunity-appeal-correction-assignment__slot-hint danger__color">
                                        {{ text('select at least one criterion') }}
                                    </div>
                                </div>
                            </div>

                            <div class="opportunity-appeal-correction-assignment__substitution-actions">
                                <button
                                    class="button button--sm button--primary"
                                    :class="{ 'disabled': !canConfirmSubstitution(slot) }"
                                    :disabled="!canConfirmSubstitution(slot)"
                                    @click="confirmSubstitution(slot)">

                                    {{ slot.substituting ? text('substituting') : text('confirm substitution') }}
                                </button>

                                <button
                                    class="button button--sm button--text"
                                    :class="{ 'disabled': slot.substituting }"
                                    :disabled="slot.substituting"
                                    @click="cancelSubstitution(slot)">

                                    <?= i::__('Cancelar') ?>
                                </button>
                            </div>
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

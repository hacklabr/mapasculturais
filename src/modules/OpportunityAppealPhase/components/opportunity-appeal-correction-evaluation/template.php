<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-alert
    mc-card
    mc-icon
    mc-loading
    mc-modal
');
?>

<div class="opportunity-appeal-correction-evaluation">
    <mc-loading v-if="loading" :condition="loading"><?= i::__('carregando...') ?></mc-loading>

    <mc-alert v-else-if="error" type="danger">{{ error.message }}</mc-alert>

    <template v-else>
        <mc-card>
            <template #title>
                <h3><?= i::__('Dados da designação') ?></h3>
            </template>
            <template #content>
                <dl class="opportunity-appeal-correction-evaluation__summary">
                    <div class="opportunity-appeal-correction-evaluation__info opportunity-appeal-correction-evaluation__info--wide">
                        <dt><?= i::__('Oportunidade') ?></dt>
                        <dd>{{ environment.opportunityName }}</dd>
                    </div>
                    <div class="opportunity-appeal-correction-evaluation__info">
                        <dt><?= i::__('Inscrição') ?></dt>
                        <dd>{{ environment.registrationNumber }}</dd>
                    </div>
                    <div class="opportunity-appeal-correction-evaluation__info">
                        <dt><?= i::__('Prazo') ?></dt>
                        <dd>{{ formattedDeadline || text('no-deadline') }}</dd>
                    </div>
                    <div class="opportunity-appeal-correction-evaluation__info opportunity-appeal-correction-evaluation__info--wide">
                        <dt><?= i::__('Avaliador original') ?></dt>
                        <dd>{{ environment.targetEvaluatorName }}</dd>
                    </div>
                    <div class="opportunity-appeal-correction-evaluation__info">
                        <dt><?= i::__('Status') ?></dt>
                        <dd>
                            <span class="opportunity-appeal-correction-evaluation__badge" :class="'opportunity-appeal-correction-evaluation__badge--' + environment.status">{{ statusLabel }}</span>
                        </dd>
                    </div>
                    <div class="opportunity-appeal-correction-evaluation__info">
                        <dt><?= i::__('Tipo de correção') ?></dt>
                        <dd>
                            <span class="opportunity-appeal-correction-evaluation__badge">{{ correctionTypeLabel }}</span>
                        </dd>
                    </div>
                </dl>
            </template>
        </mc-card>

        <mc-card v-if="environment.committeeOpinion">
            <template #title>
                <h3><?= i::__('Parecer da Comissão de Recursos') ?></h3>
            </template>
            <template #content>
                <div class="opportunity-appeal-correction-evaluation__list">
                    <div class="opportunity-appeal-correction-evaluation__item" v-for="opinion in environment.committeeOpinion.evaluations" :key="opinion.evaluationId">
                        <p class="semibold">
                            {{ opinion.valuerName }}
                            <span v-if="opinion.resultString"> · {{ opinion.resultString }}</span>
                        </p>
                        <p v-if="opinion.evaluationData?.obs">{{ opinion.evaluationData.obs }}</p>
                    </div>
                </div>
            </template>
        </mc-card>

        <!-- F7 (#51): formulário conforme o método do slot -->
        <mc-card v-if="isTechnical">
            <template #title>
                <h3><?= i::__('Critérios liberados para correção') ?></h3>
            </template>
            <template #content>
                <div class="opportunity-appeal-correction-evaluation__list">
                    <div class="opportunity-appeal-correction-evaluation__item" v-for="criterion in criteriaList" :key="criterion.id">
                        <div class="opportunity-appeal-correction-evaluation__item-header">
                            <h4 class="bold">{{ criterion.title }}</h4>
                            <div class="opportunity-appeal-correction-evaluation__item-meta">
                                <span><?= i::__('Nota máxima') ?>: <strong>{{ criterion.max }}</strong></span>
                                <span><?= i::__('Peso') ?>: <strong>{{ criterion.weight }}</strong></span>
                            </div>
                        </div>

                        <div class="opportunity-appeal-correction-evaluation__compare">
                            <div class="opportunity-appeal-correction-evaluation__original">
                                <span class="opportunity-appeal-correction-evaluation__label"><?= i::__('Nota original') ?></span>
                                <span class="opportunity-appeal-correction-evaluation__value">{{ originalNote(criterion) }}</span>
                            </div>
                            <div class="field">
                                <label :for="'corrected-criterion-' + criterion.id"><?= i::__('Nota corrigida') ?></label>
                                <input
                                    :id="'corrected-criterion-' + criterion.id"
                                    :disabled="isLocked"
                                    :max="criterion.max"
                                    min="0"
                                    step="0.1"
                                    type="number"
                                    v-model.number="formData.data[criterion.id]"
                                    @input="handleInput(criterion)">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="opportunity-appeal-correction-evaluation__total">
                    <span><?= i::__('Pontuação total') ?></span>
                    <strong>{{ totalScore }}</strong>
                </div>
            </template>
        </mc-card>

        <mc-card v-else-if="isDocumentary">
            <template #title>
                <h3><?= i::__('Campos liberados para correção') ?></h3>
            </template>
            <template #content>
                <div class="opportunity-appeal-correction-evaluation__list">
                    <div class="opportunity-appeal-correction-evaluation__item" v-for="field in fieldsList" :key="field.id">
                        <div class="opportunity-appeal-correction-evaluation__item-header">
                            <h4 class="bold">{{ field.title }}</h4>
                        </div>

                        <div class="opportunity-appeal-correction-evaluation__compare">
                            <div class="opportunity-appeal-correction-evaluation__original">
                                <span class="opportunity-appeal-correction-evaluation__label"><?= i::__('Avaliação original') ?></span>
                                <span class="opportunity-appeal-correction-evaluation__value">{{ originalFieldEvaluation(field) }}</span>
                            </div>
                            <div class="field">
                                <label><?= i::__('Avaliação corrigida') ?></label>
                                <div class="opportunity-appeal-correction-evaluation__field-options">
                                    <label class="field__checkbox">
                                        <input type="radio" value="" v-model="formData.data[field.id].evaluation" :disabled="isLocked" :name="'corrected-field-' + field.id">
                                        <span><?= i::__('Não avaliada') ?></span>
                                    </label>
                                    <label class="field__checkbox">
                                        <input type="radio" value="valid" v-model="formData.data[field.id].evaluation" :disabled="isLocked" :name="'corrected-field-' + field.id">
                                        <span><?= i::__('Válida') ?></span>
                                    </label>
                                    <label class="field__checkbox">
                                        <input type="radio" value="invalid" v-model="formData.data[field.id].evaluation" :disabled="isLocked" :name="'corrected-field-' + field.id">
                                        <span><?= i::__('Inválida') ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="field">
                            <label :for="'corrected-field-obs-' + field.id"><?= i::__('Observações do campo') ?></label>
                            <textarea
                                :id="'corrected-field-obs-' + field.id"
                                :name="'corrected-field-obs-' + field.id"
                                :disabled="isLocked"
                                v-model="formData.data[field.id].obs"></textarea>
                        </div>
                    </div>
                </div>
            </template>
        </mc-card>

        <mc-card v-else-if="isSimple">
            <template #title>
                <h3><?= i::__('Correção do resultado') ?></h3>
            </template>
            <template #content>
                <div class="opportunity-appeal-correction-evaluation__list">
                    <mc-alert type="helper"><?= i::__('Método de avaliação sem critérios: a correção libera o resultado integral da avaliação.') ?></mc-alert>

                    <div class="opportunity-appeal-correction-evaluation__item">
                        <div class="opportunity-appeal-correction-evaluation__compare">
                            <div class="opportunity-appeal-correction-evaluation__original">
                                <span class="opportunity-appeal-correction-evaluation__label"><?= i::__('Status original') ?></span>
                                <span class="opportunity-appeal-correction-evaluation__value">{{ originalStatus() }}</span>
                            </div>
                            <div class="field">
                                <label for="corrected-global-status"><?= i::__('Status corrigido') ?></label>
                                <select
                                    id="corrected-global-status"
                                    name="corrected-global-status"
                                    v-model="formData.data.status"
                                    :disabled="isLocked">

                                    <option value="" disabled><?= i::__('Selecione o status') ?></option>
                                    <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="field">
                            <label for="corrected-global-obs"><?= i::__('Observações') ?></label>
                            <textarea
                                id="corrected-global-obs"
                                name="corrected-global-obs"
                                :disabled="isLocked"
                                v-model="formData.data.obs"></textarea>
                        </div>
                    </div>
                </div>
            </template>
        </mc-card>

        <mc-alert v-if="isLocked" type="warning"><?= i::__('Correção enviada, edição bloqueada.') ?></mc-alert>

        <div class="opportunity-appeal-correction-evaluation__actions" v-if="!isLocked">
            <button class="button button--primary-outline" :class="{'disabled': savingDraft}" @click="saveDraft()">
                <?= i::__('Salvar rascunho') ?>
            </button>

            <mc-modal title="<?= i::__('Enviar correção') ?>">
                <template #default>
                    <p><?= i::__('Ao confirmar, a correção será aplicada à avaliação e a edição será bloqueada. Esta ação não pode ser desfeita.') ?></p>
                </template>

                <template #actions="modal">
                    <button class="button button--text" @click="modal.close()"><?= i::__('Cancelar') ?></button>
                    <button class="button button--primary" :class="{'disabled': sending}" @click="sendCorrection(); modal.close()">
                        <?= i::__('Confirmar envio') ?>
                    </button>
                </template>

                <template #button="modal">
                    <button class="button button--primary" @click="modal.open()">
                        <?= i::__('Enviar definitivamente') ?>
                    </button>
                </template>
            </mc-modal>
        </div>
    </template>
</div>

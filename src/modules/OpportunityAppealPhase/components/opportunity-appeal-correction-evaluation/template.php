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

<div class="opportunity-appeal-correction-evaluation">
    <mc-loading v-if="loading" :condition="loading"><?= i::__('carregando...') ?></mc-loading>

    <mc-alert v-else-if="error" type="danger">{{ error.message }}</mc-alert>

    <template v-else>
        <section class="grid-12 section">
            <h3 class="col-12"><?= i::__('Correção de avaliação (recurso)') ?></h3>
            <div class="section__content col-12">
                <div class="card">
                    <div class="grid-12">
                        <div class="col-6">
                            <label><?= i::__('Oportunidade') ?></label>
                            <p class="semibold">{{ environment.opportunityName }}</p>
                        </div>
                        <div class="col-3">
                            <label><?= i::__('Inscrição') ?></label>
                            <p class="semibold">{{ environment.registrationNumber }}</p>
                        </div>
                        <div class="col-3">
                            <label><?= i::__('Prazo') ?></label>
                            <p class="semibold">{{ formattedDeadline }}</p>
                        </div>
                        <div class="col-6">
                            <label><?= i::__('Avaliador original') ?></label>
                            <p class="semibold">{{ environment.targetEvaluatorName }}</p>
                        </div>
                        <div class="col-3">
                            <label><?= i::__('Status') ?></label>
                            <p><span class="opportunity-appeal-correction-evaluation__badge">{{ statusLabel }}</span></p>
                        </div>
                        <div class="col-3">
                            <label><?= i::__('Tipo de correção') ?></label>
                            <p><span class="opportunity-appeal-correction-evaluation__badge">{{ correctionTypeLabel }}</span></p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section v-if="environment.committeeOpinion" class="grid-12 section">
            <h3 class="col-12"><?= i::__('Parecer da Comissão de Recursos') ?></h3>
            <div class="section__content col-12">
                <div class="card" v-for="opinion in environment.committeeOpinion.evaluations" :key="opinion.evaluationId">
                    <p>
                        <strong>{{ opinion.valuerName }}</strong>
                        <span v-if="opinion.resultString"> · {{ opinion.resultString }}</span>
                    </p>
                    <p v-if="opinion.evaluationData?.obs">{{ opinion.evaluationData.obs }}</p>
                </div>
            </div>
        </section>

        <!-- F7 (#51): formulário conforme o método do slot -->
        <section v-if="isTechnical" class="grid-12 section">
            <h3 class="col-12"><?= i::__('Critérios liberados para correção') ?></h3>
            <div class="section__content col-12">
                <div class="card">
                    <div class="opportunity-appeal-correction-evaluation__criterion" v-for="criterion in criteriaList" :key="criterion.id">
                        <label><strong>{{ criterion.title }}</strong></label>
                        <div class="grid-12">
                            <div class="col-6">
                                <label><?= i::__('Nota original') ?></label>
                                <input disabled min="0" step="0.1" type="number" :value="originalNote(criterion)">
                            </div>
                            <div class="col-6">
                                <label><?= i::__('Nota corrigida') ?></label>
                                <input :disabled="isLocked" min="0" step="0.1" type="number" v-model.number="formData.data[criterion.id]" @input="handleInput(criterion)">
                            </div>
                        </div>
                        <div class="grid-12">
                            <small class="col-8"><?= i::__('Nota máxima') ?>: <strong>{{ criterion.max }}</strong></small>
                            <small class="col-4"><?= i::__('Peso') ?>: <strong>{{ criterion.weight }}</strong></small>
                        </div>
                    </div>
                    <div class="opportunity-appeal-correction-evaluation__results">
                        <h4><?= i::__('Pontuação total') ?>: <strong>{{ totalScore }}</strong></h4>
                    </div>
                </div>
            </div>
        </section>

        <section v-else-if="isDocumentary" class="grid-12 section">
            <h3 class="col-12"><?= i::__('Campos liberados para correção') ?></h3>
            <div class="section__content col-12">
                <div class="card">
                    <div class="opportunity-appeal-correction-evaluation__criterion" v-for="field in fieldsList" :key="field.id">
                        <label><strong>{{ field.title }}</strong></label>
                        <div class="grid-12">
                            <div class="col-6">
                                <label><?= i::__('Avaliação original') ?></label>
                                <p class="semibold">{{ originalFieldEvaluation(field) }}</p>
                            </div>
                            <div class="col-6">
                                <label :for="'corrected-field-' + field.id"><?= i::__('Avaliação corrigida') ?></label>
                                <div class="opportunity-appeal-correction-evaluation__field-options">
                                    <label class="opportunity-appeal-correction-evaluation__field-option">
                                        <input type="radio" value="" v-model="formData.data[field.id].evaluation" :disabled="isLocked" :name="'corrected-field-' + field.id">
                                        <?= i::__('Não avaliada') ?>
                                    </label>
                                    <label class="opportunity-appeal-correction-evaluation__field-option">
                                        <input type="radio" value="valid" v-model="formData.data[field.id].evaluation" :disabled="isLocked" :name="'corrected-field-' + field.id">
                                        <?= i::__('Válida') ?>
                                    </label>
                                    <label class="opportunity-appeal-correction-evaluation__field-option">
                                        <input type="radio" value="invalid" v-model="formData.data[field.id].evaluation" :disabled="isLocked" :name="'corrected-field-' + field.id">
                                        <?= i::__('Inválida') ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <label :for="'corrected-field-obs-' + field.id"><?= i::__('Observações do campo') ?></label>
                        <textarea
                            :id="'corrected-field-obs-' + field.id"
                            :name="'corrected-field-obs-' + field.id"
                            :disabled="isLocked"
                            v-model="formData.data[field.id].obs"></textarea>
                    </div>
                </div>
            </div>
        </section>

        <section v-else-if="isSimple" class="grid-12 section">
            <h3 class="col-12"><?= i::__('Correção do resultado') ?></h3>
            <div class="section__content col-12">
                <div class="card">
                    <div class="grid-12">
                        <div class="col-6">
                            <label><?= i::__('Status original') ?></label>
                            <p class="semibold">{{ originalStatus() }}</p>
                        </div>
                        <div class="col-6">
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
                        <div class="col-12">
                            <label for="corrected-global-obs"><?= i::__('Observações') ?></label>
                            <textarea
                                id="corrected-global-obs"
                                name="corrected-global-obs"
                                :disabled="isLocked"
                                v-model="formData.data.obs"></textarea>
                        </div>
                    </div>
                    <mc-alert type="helper"><?= i::__('Método de avaliação sem critérios: a correção libera o resultado integral da avaliação.') ?></mc-alert>
                </div>
            </div>
        </section>

        <mc-alert v-if="isLocked" type="warning"><?= i::__('Correção enviada, edição bloqueada.') ?></mc-alert>

        <div class="grid-12" v-if="!isLocked">
            <div class="col-6">
                <button class="button button--primary-outline" :class="{'btn disabled': savingDraft}" @click="saveDraft()">
                    <?= i::__('Salvar rascunho') ?>
                </button>
            </div>
            <div class="col-6">
                <mc-modal title="<?= i::__('Enviar correção') ?>">
                    <template #default>
                        <p><?= i::__('Ao confirmar, a correção será aplicada à avaliação e a edição será bloqueada. Esta ação não pode ser desfeita.') ?></p>
                    </template>

                    <template #actions="modal">
                        <button class="button button--text" @click="modal.close()"><?= i::__('Cancelar') ?></button>
                        <button class="button button--primary" :class="{'btn disabled': sending}" @click="sendCorrection(); modal.close()">
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
        </div>
    </template>
</div>

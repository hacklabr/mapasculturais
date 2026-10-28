<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    entity-file
    mc-alert
    mc-card
    mc-icon
    mc-loading
    mc-modal
    v1-embed-tool
');
?>

<div class="opportunity-appeal-correction-evaluation">
    <mc-loading v-if="loading" :condition="loading"><?= i::__('carregando...') ?></mc-loading>

    <mc-alert v-else-if="error" type="danger">{{ error.message }}</mc-alert>

    <template v-else>
        <div class="opportunity-appeal-correction-evaluation__workspace">
            <section class="opportunity-appeal-correction-evaluation__registration">
                <mc-card>
                    <template #title>
                        <h3><?= i::__('Dados da inscrição') ?></h3>
                    </template>
                    <template #content>
                        <p class="opportunity-appeal-correction-evaluation__registration-hint">
                            <?= i::__('Consulte os dados da inscrição para refazer a análise antes de corrigir a nota.') ?>
                        </p>

                        <dl v-if="registrationContext" class="opportunity-appeal-correction-evaluation__registration-summary">
                            <div class="opportunity-appeal-correction-evaluation__info">
                                <dt><?= i::__('Inscrição') ?></dt>
                                <dd>{{ registrationContext.number }}</dd>
                            </div>
                            <div v-if="registrationOwnerName" class="opportunity-appeal-correction-evaluation__info opportunity-appeal-correction-evaluation__info--wide">
                                <dt><?= i::__('Agente proponente') ?></dt>
                                <dd>
                                    <a v-if="registrationContext.owner?.singleUrl" :href="registrationContext.owner.singleUrl" target="_blank" rel="noopener">
                                        {{ registrationOwnerName }}
                                    </a>
                                    <template v-else>{{ registrationOwnerName }}</template>
                                </dd>
                            </div>
                            <div v-if="registrationOwnerDescription" class="opportunity-appeal-correction-evaluation__info opportunity-appeal-correction-evaluation__info--wide">
                                <dt><?= i::__('Descrição do agente') ?></dt>
                                <dd>{{ registrationOwnerDescription }}</dd>
                            </div>
                            <div v-if="registrationContext.category" class="opportunity-appeal-correction-evaluation__info">
                                <dt><?= i::__('Categoria') ?></dt>
                                <dd>{{ registrationContext.category }}</dd>
                            </div>
                            <div v-if="registrationContext.range" class="opportunity-appeal-correction-evaluation__info">
                                <dt><?= i::__('Faixa') ?></dt>
                                <dd>{{ registrationContext.range }}</dd>
                            </div>
                            <div v-if="registrationContext.proponentType" class="opportunity-appeal-correction-evaluation__info">
                                <dt><?= i::__('Tipo de proponente') ?></dt>
                                <dd>{{ registrationContext.proponentType }}</dd>
                            </div>
                            <div v-if="registrationOwnerAreas" class="opportunity-appeal-correction-evaluation__info opportunity-appeal-correction-evaluation__info--wide">
                                <dt><?= i::__('Áreas de atuação') ?></dt>
                                <dd>{{ registrationOwnerAreas }}</dd>
                            </div>
                        </dl>

                        <div class="opportunity-appeal-correction-evaluation__registration-actions">
                            <a
                                v-if="registrationContext?.singleUrl"
                                class="button button--primary-outline button--sm"
                                :href="registrationContext.singleUrl"
                                target="_blank"
                                rel="noopener">
                                <?= i::__('Abrir inscrição completa') ?>
                            </a>
                        </div>

                        <h4 class="opportunity-appeal-correction-evaluation__registration-form-title">
                            <?= i::__('Campos do formulário') ?>
                        </h4>
                        <div class="opportunity-appeal-correction-evaluation__registration-embed">
                            <v1-embed-tool
                                route="registrationevaluationtionformview"
                                iframe-id="correction-registration"
                                :id="environment.registrationId"
                                min-height="200px">
                            </v1-embed-tool>
                        </div>
                    </template>
                </mc-card>
            </section>

            <section class="opportunity-appeal-correction-evaluation__correction">
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
                    <div v-if="environment.targetEvaluatorName" class="opportunity-appeal-correction-evaluation__info opportunity-appeal-correction-evaluation__info--wide">
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

        <mc-card v-if="committeeRegistration">
            <template #title>
                <h3><?= i::__('Parecer da Comissão de Recursos') ?></h3>
            </template>
            <template #content>
                <!--
                    Mesma visão do "Exibir detalhamento" (um card por parecerista).
                    Markup espelhado das parts */evaluations-details — sem importar
                    evaluation-*-detail (o init.php deles assume view de inscrição).
                -->
                <div class="registration-results opportunity-appeal-correction-evaluation__committee-opinion">
                    <div class="registration-results__content">

                        <!-- Técnico: consolidado + cards com pontuação -->
                        <template v-if="committeeOpinionMethod === 'technical'">
                            <div class="registration-results__card">
                                <div class="registration-results__card-header">
                                    <div class="registration-results__card-title">
                                        <h4 class="bold"><?= i::__('Resultado Consolidado') ?></h4>
                                    </div>
                                    <div>
                                        <p><label><?= i::__('Pontuação total: ') ?></label> <strong>{{ committeeRegistration.score }}</strong></p>
                                        <p v-if="committeeRegistration.consolidatedDetails?.maxScore">
                                            <label><?= i::__('Pontuação máxima: ') ?></label>
                                            <strong>{{ committeeRegistration.consolidatedDetails.maxScore }}</strong>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-for="(evaluation, index) in committeeRegistration.evaluationsDetails"
                                :key="evaluation.id || index"
                                class="registration-results__card">
                                <div class="registration-results__card-header">
                                    <div class="registration-results__card-title">
                                        <h4 class="registration-results__opinion-title bold">
                                            <?= i::__('Parecerista: ') ?>
                                            <template v-if="evaluation.valuer && evaluation.committeeSequentialNumber">
                                                #{{ evaluation.committeeSequentialNumber }} - ({{ evaluation.valuer.id }} - {{ evaluation.valuer.name }})
                                            </template>
                                            <template v-else-if="evaluation.valuer">
                                                #{{ index + 1 }} - ({{ evaluation.valuer.id }} - {{ evaluation.valuer.name }})
                                            </template>
                                            <template v-else-if="evaluation.committeeSequentialNumber">
                                                #{{ evaluation.committeeSequentialNumber }}
                                            </template>
                                            <template v-else>#{{ index + 1 }}</template>
                                        </h4>
                                        <p v-if="committeeRegistration.consolidatedResult">
                                            <label><?= i::__('Pontuação total: ') ?></label>
                                            <strong>{{ committeeRegistration.consolidatedResult }}</strong>
                                        </p>
                                    </div>
                                </div>
                                <div class="registration-results__card-content">
                                    <div class="registration-results__opinion registration-results__opinion--document">
                                        <h5 class="registration-results__opinion-title bold"><?= i::__('Parecer') ?>:</h5>
                                        <div class="registration-results__opinion-text">
                                            <p>{{ evaluation.obs }}</p>
                                        </div>
                                    </div>
                                </div>
                                <div v-if="evaluation.scores?.length" class="registration-results__card-content">
                                    <div class="registration-results__opinion registration-results__opinion--document">
                                        <h5 class="registration-results__opinion-title bold"><?= i::__('Detalhamento da pontuação') ?>:</h5>
                                        <ul>
                                            <li v-for="section in evaluation.scores" :key="section.id || section.name">
                                                {{ section.name }}: <strong>{{ section.score }}</strong>
                                                <em>(<?= i::__('pontuação máxima') ?>: {{ section.maxScore }})</em>
                                                <ul>
                                                    <li v-for="cri in section.criteria" :key="cri.id">
                                                        {{ cri.title }}: <strong>{{ cri.score }}</strong>
                                                        <em>(<?= i::__('pontuação máxima') ?>: {{ cri.maxScore }})</em>
                                                    </li>
                                                </ul>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <!-- Documental -->
                        <template v-else-if="committeeOpinionMethod === 'documentary'">
                            <div
                                v-for="(detail, index) in committeeRegistration.evaluationsDetails"
                                :key="detail.id || index"
                                class="registration-results__card">
                                <div class="registration-results__card-header">
                                    <div class="registration-results__card-title">
                                        <h4 class="registration-results__opinion-title bold">
                                            <?= i::__('Parecerista: ') ?>
                                            <template v-if="detail.valuer && detail.committeeSequentialNumber">
                                                #{{ detail.committeeSequentialNumber }} - ({{ detail.valuer.id }} - {{ detail.valuer.name }})
                                            </template>
                                            <template v-else-if="detail.valuer">
                                                #{{ index + 1 }} - ({{ detail.valuer.id }} - {{ detail.valuer.name }})
                                            </template>
                                            <template v-else-if="detail.committeeSequentialNumber">
                                                #{{ detail.committeeSequentialNumber }}
                                            </template>
                                            <template v-else>#{{ index + 1 }}</template>
                                        </h4>
                                        <p v-if="detail.evaluationResult != null">
                                            <label><?= i::__('Resultado final: ') ?></label>
                                            <strong :class="documentaryStatusColor(detail.evaluationResult)">
                                                <mc-icon name="circle" :class="documentaryStatusColor(detail.evaluationResult)"></mc-icon>
                                                {{ documentaryStatusLabel(detail.evaluationResult) }}
                                            </strong>
                                        </p>
                                    </div>
                                </div>
                                <template v-for="(item, itemKey) in detail.evaluations" :key="itemKey">
                                    <div v-if="itemKey !== 'valuer'" class="registration-results__card-content">
                                        <div class="registration-results__opinion registration-results__opinion--document">
                                            <div class="registration-results__opinion-text">
                                                <p>
                                                    <mc-icon name="circle" :class="documentaryStatusColor(item.evaluation)"></mc-icon>
                                                    <strong :class="documentaryStatusColor(item.evaluation)">
                                                        {{ documentaryStatusLabel(item.evaluation) }}
                                                    </strong>
                                                </p>
                                                <p><strong>{{ item.label }}</strong></p>
                                                <div v-if="item.obs">
                                                    <h5 class="registration-results__opinion-title bold"><?= i::__('Parecer') ?>:</h5>
                                                    <ul><li>{{ item.obs }}</li></ul>
                                                </div>
                                                <div v-if="item.obs_items">
                                                    <h5 class="registration-results__opinion-title bold"><?= i::__('Detalhamento') ?>:</h5>
                                                    <ul><li>{{ item.obs_items }}</li></ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <!-- Contínuo / simples / qualificação / fallback: parecerista + justificativa -->
                        <template v-else>
                            <div
                                v-for="(evaluation, index) in committeeRegistration.evaluationsDetails"
                                :key="evaluation.id || index"
                                class="registration-results__card">
                                <div class="registration-results__card-header">
                                    <div class="registration-results__card-title">
                                        <h4 class="registration-results__opinion-title bold">
                                            <?= i::__('Parecerista: ') ?>
                                            <template v-if="evaluation.valuer && evaluation.committeeSequentialNumber">
                                                #{{ evaluation.committeeSequentialNumber }} - ({{ evaluation.valuer.id }} - {{ evaluation.valuer.name }})
                                            </template>
                                            <template v-else-if="evaluation.valuer">
                                                #{{ index + 1 }} - ({{ evaluation.valuer.id }} - {{ evaluation.valuer.name }})
                                            </template>
                                            <template v-else-if="evaluation.committeeSequentialNumber">
                                                #{{ evaluation.committeeSequentialNumber }}
                                            </template>
                                            <template v-else>#{{ index + 1 }}</template>
                                        </h4>
                                    </div>
                                </div>
                                <div class="registration-results__card-content">
                                    <div class="registration-results__opinion registration-results__opinion--document">
                                        <h5 class="registration-results__opinion-title bold"><?= i::__('Justificativa') ?>:</h5>
                                        <div class="registration-results__opinion-text">
                                            <p>{{ evaluation.obs }}</p>
                                        </div>
                                        <div v-if="committeeEvaluationEntity(evaluation)" class="registration-results__opinion-attachment">
                                            <entity-file
                                                :entity="committeeEvaluationEntity(evaluation)"
                                                group-name="evaluationAttachment"
                                                classes="col-12">
                                            </entity-file>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>

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
            </section>
        </div>
    </template>
</div>

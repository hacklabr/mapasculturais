/**
 * opportunity-appeal-correction-assignment (F2 / issue #18)
 *
 * Modal de designação de corretores por slot: lista as avaliações da fase
 * principal da inscrição (uma por avaliador), permite marcar quais notas serão
 * corrigidas e designar 1 corretor por slot marcado (dono do slot ou membro
 * da Comissão de Recursos — mesma regra de
 * RegistrationAppealReview::eligibleCorrectors()).
 *
 * Abertura: ver docblock do init.php deste componente (evento global
 * 'opportunity-appeal-correction-assignment:open' com {opportunity, registration}).
 *
 * Backend consumido (somente endpoints existentes):
 * - GET /api/registrationevaluation/find  → slots da inscrição.
 * - /registrationappealreview (API canônica da entidade) → criação e
 *   acompanhamento; habilitada somente quando 'endpointAvailable' (controller
 *   da entidade registrado no backend).
 */

app.component('opportunity-appeal-correction-assignment', {
    template: $TEMPLATES['opportunity-appeal-correction-assignment'],

    emits: ['saved'],

    setup() {
        // os textos estão localizados no arquivo texts.php deste componente
        const text = Utils.getTexts('opportunity-appeal-correction-assignment');
        return { text };
    },

    data() {
        return {
            opportunityId: null,
            registrationId: null,
            registrationNumber: null,
            slots: [],
            reviews: [],
            loading: false,
            saving: false,
        };
    },

    computed: {
        config() {
            return $MAPAS.config.appealCorrectionAssignment || {};
        },

        endpointAvailable() {
            return this.config.endpointAvailable === true;
        },

        /**
         * Contexto elegível: fase técnica com fase de recurso ativa e Comissão
         * de Recursos injetada para a oportunidade recebida (espelha os gates
         * de RegistrationAppealReview::eligibleCorrectors()).
         */
        correctionEligible() {
            return this.appealPhaseId != null && Array.isArray(this.committee);
        },

        appealPhaseId() {
            const appeal_phases = this.config.appealPhases || {};
            return this.opportunityId != null
                ? appeal_phases[this.opportunityId] ?? null
                : null;
        },

        committee() {
            const committees = this.config.committees || {};
            return this.opportunityId != null
                ? committees[this.opportunityId] ?? null
                : null;
        },

        markedSlots() {
            return this.slots.filter(slot => slot.checked);
        },

        hasInvalidSelection() {
            return this.markedSlots.some(slot => !this.normalizeId(slot.correctorUserId));
        },

        canSave() {
            return this.endpointAvailable
                && this.correctionEligible
                && !this.loading
                && !this.saving
                && this.markedSlots.length > 0
                && !this.hasInvalidSelection;
        },

        designatedCount() {
            return this.slots.filter(slot => this.reviewForSlot(slot) != null).length;
        },

        sentCount() {
            const sent_status = this.config.statusSent ?? 2;
            return this.reviews.filter(review => this.statusNumber(review) === sent_status).length;
        },

        progressSummary() {
            return this.text('progress summary')
                .replace('%s', this.designatedCount)
                .replace('%s', this.slots.length)
                .replace('%s', this.sentCount);
        },

        subtitle() {
            const number = this.registrationNumber || this.slots[0]?.registration?.number || null;
            return number ? `${this.text('registration')} ${number}` : '';
        },
    },

    mounted() {
        window.addEventListener('opportunity-appeal-correction-assignment:open', this.handleOpenEvent);
    },

    beforeUnmount() {
        window.removeEventListener('opportunity-appeal-correction-assignment:open', this.handleOpenEvent);
    },

    methods: {
        /**
         * Interface pública (F1): detail = {opportunity, registration}.
         * Aceita instâncias Entity do SDK ou objetos com ao menos {id}.
         */
        handleOpenEvent(event) {
            const { opportunity, registration } = event?.detail || {};

            const opportunity_id = this.normalizeId(opportunity);
            const registration_id = this.normalizeId(registration);

            if (!opportunity_id || !registration_id) {
                console.error('opportunity-appeal-correction-assignment: evento requer {opportunity, registration} com id válido');
                return;
            }

            this.opportunityId = opportunity_id;
            this.registrationId = registration_id;
            this.registrationNumber = registration?.number || null;
            this.slots = [];
            this.reviews = [];

            this.$refs.modal?.open();
            this.loadData();
        },

        async loadData() {
            this.loading = true;

            try {
                await Promise.all([
                    this.fetchSlots(),
                    this.endpointAvailable ? this.fetchReviews() : Promise.resolve(),
                ]);
            } catch (error) {
                console.error('opportunity-appeal-correction-assignment:', error);
            } finally {
                this.loading = false;
            }
        },

        /**
         * Lista as N avaliações da fase principal da inscrição (uma por avaliador),
         * via API de RegistrationEvaluation (a API filtra por permissão de visão).
         * Usa leitura raw: os campos escalarizados pelo jsonSerialize (user, agent)
         * chegam sem transformação do SDK.
         */
        async fetchSlots() {
            const api = new API('registrationevaluation');
            const evaluations = await api.fetch('find', {
                '@select': 'id,user.id,status,result,resultString,registration.{id,number},agent.{id,name}',
                'registration': `EQ(${this.registrationId})`,
                '@order': 'id ASC',
            }, { rawProcessor: data => data });

            this.slots = (evaluations || []).map(evaluation => ({
                id: evaluation.id,
                user: evaluation.user,
                agent: evaluation.agent,
                status: evaluation.status,
                result: evaluation.result,
                resultString: evaluation.resultString,
                registration: evaluation.registration,
                checked: false,
                correctorUserId: null,
            }));

            this.preSelectCorrectors();
        },

        /**
         * Acompanhamento: designações existentes da inscrição. Disponível
         * somente quando a API da entidade existe (endpointAvailable).
         */
        async fetchReviews() {
            if (!this.endpointAvailable) {
                return;
            }

            const api = new API('registrationappealreview');
            const reviews = await api.fetch('find', {
                '@select': 'id,originalEvaluation.id,status,correctionType,endsAt,sentTimestamp',
                'registration': `EQ(${this.registrationId})`,
                '@order': 'id ASC',
            }, { rawProcessor: data => data });

            this.reviews = (reviews || []).map(review => ({
                id: review.id,
                originalEvaluationId: this.normalizeId(review.originalEvaluation),
                status: review.status,
                correctionType: review.correctionType,
                endsAt: review.endsAt,
                sentTimestamp: review.sentTimestamp,
            }));
        },

        /**
         * Opções de corretor do slot: dono do slot + Comissão de Recursos,
         * sem duplicatas (mesma composição de eligibleCorrectors()).
         */
        correctorOptions(slot) {
            if (!this.correctionEligible) {
                return [];
            }

            const options = [];
            const seen = new Set();

            const owner_id = this.slotUserId(slot);
            if (owner_id != null) {
                options.push({
                    value: owner_id,
                    label: `${this.slotAgentName(slot)} (${this.text('slot owner tag')})`,
                });
                seen.add(owner_id);
            }

            for (const member of this.committee) {
                if (seen.has(member.userId)) {
                    continue;
                }

                options.push({
                    value: member.userId,
                    label: `${member.name} (${this.text('committee tag')})`,
                });
                seen.add(member.userId);
            }

            return options;
        },

        /**
         * Slot pode ser marcado: contexto elegível, sem designação ativa
         * (o índice único de slots ativos impõe a mesma regra no backend).
         */
        slotSelectable(slot) {
            return this.correctionEligible && !this.activeReviewForSlot(slot);
        },

        preSelectCorrectors() {
            for (const slot of this.slots) {
                slot.correctorUserId = this.slotUserId(slot);
            }
        },

        activeReviewForSlot(slot) {
            const active_statuses = this.config.activeStatuses || [0, 1, 3];
            return this.reviews.find(review =>
                review.originalEvaluationId === slot.id
                && active_statuses.includes(this.statusNumber(review))
            ) || null;
        },

        reviewForSlot(slot) {
            return this.reviews.find(review => review.originalEvaluationId === slot.id) || null;
        },

        statusNumber(review) {
            return parseInt(review?.status, 10);
        },

        statusLabel(review) {
            const status_key = {
                0: 'status designated',
                1: 'status draft',
                2: 'status sent',
                3: 'status reopened',
            }[this.statusNumber(review)];

            return status_key ? this.text(status_key) : String(review?.status ?? '');
        },

        statusClass(review) {
            return {
                0: 'opportunity-appeal-correction-assignment__status-label--designated',
                1: 'opportunity-appeal-correction-assignment__status-label--draft',
                2: 'opportunity-appeal-correction-assignment__status-label--sent',
                3: 'opportunity-appeal-correction-assignment__status-label--reopened',
            }[this.statusNumber(review)] || '';
        },

        reviewDeadline(review) {
            return this.formatDate(review?.endsAt);
        },

        reviewSentAt(review) {
            return this.formatDate(review?.sentTimestamp);
        },

        formatDate(value) {
            if (!value) {
                return '';
            }

            const raw = typeof value === 'object' ? (value.date ?? null) : value;
            if (!raw) {
                return '';
            }

            try {
                const mcdate = new McDate(new Date(raw));
                return `${mcdate.date('2-digit year')} ${mcdate.time()}`;
            } catch (error) {
                return String(raw).slice(0, 16).replace('T', ' ');
            }
        },

        slotUserId(slot) {
            return this.normalizeId(slot?.user);
        },

        slotAgentName(slot) {
            return slot?.agent?.name || slot?.agent?.email || this.text('slot owner tag');
        },

        /**
         * Cria 1 RegistrationAppealReview por nota marcada, via API canônica
         * da entidade. Defaults: status=DESIGNATED, correction_type=official,
         * prazo e escopo livres (CA-4 fica para a onda de parametrização).
         */
        async saveDesignations(close) {
            if (!this.canSave) {
                return;
            }

            const messages = useMessages();
            this.saving = true;

            const api = new API('registrationappealreview');
            const url = api.createUrl('index');

            try {
                for (const slot of this.markedSlots) {
                    const response = await api.POST(url, this.buildAssignmentPayload(slot));

                    if (!response.ok) {
                        const error = await response.json().catch(() => ({}));
                        throw error;
                    }
                }

                messages.success(this.text('saved'));
                this.$emit('saved', { registrationId: this.registrationId });

                await this.fetchReviews();

                close();
            } catch (error) {
                console.error('opportunity-appeal-correction-assignment:saveDesignations', error);
                messages.error(this.text('save error'));
            } finally {
                this.saving = false;
            }
        },

        /**
         * Payload de criação de 1 designação (RegistrationAppealReview) para o
         * slot informado. Defaults: status=DESIGNATED, correction_type=official,
         * prazo e escopo livres (CA-4 fica para a onda de parametrização).
         */
        buildAssignmentPayload(slot) {
            return {
                originalEvaluation: slot.id,
                registration: this.registrationId,
                appealPhase: this.appealPhaseId,
                slotOwnerUser: this.slotUserId(slot),
                correctorUser: this.normalizeId(slot.correctorUserId),
                status: this.config.statusDesignated ?? 0,
                correctionType: this.config.correctionTypeDefault || 'official',
            };
        },

        normalizeId(value) {
            if (value == null) {
                return null;
            }

            const raw_id = typeof value === 'object' ? (value.id ?? null) : value;
            if (raw_id == null) {
                return null;
            }

            const parsed = parseInt(raw_id, 10);
            return Number.isNaN(parsed) ? null : parsed;
        },
    },
});

/**
 * opportunity-appeal-correction-assignment (F2 #18 + F5 #45)
 *
 * Modal de designação de corretores por slot: lista as avaliações da fase
 * principal da inscrição (uma por avaliador), permite marcar quais notas serão
 * corrigidas e designar 1 corretor por slot marcado (dono do slot ou membro
 * da Comissão de Recursos — mesma regra de
 * RegistrationAppealReview::eligibleCorrectors()).
 *
 * F5: no painel de acompanhamento, designação ATIVA (status 0/1/3) ganha
 * "Substituir corretor" (PATCH single com {correctorUser}, CA-3 revalidado
 * server-side pelo PR6) e "Cancelar designação" (DELETE single — slot volta
 * a ser designável). Status ENVIADO (2) não tem ações.
 *
 * Abertura: ver docblock do init.php deste componente (evento global
 * 'opportunity-appeal-correction-assignment:open' com {opportunity, registration}).
 *
 * Backend consumido (somente endpoints existentes):
 * - GET /api/registrationevaluation/find  → slots da inscrição.
 * - /registrationappealreview (API canônica da entidade) → criação e
 *   acompanhamento; habilitada somente quando 'endpointAvailable' (controller
 *   da entidade registrado no backend).
 * - PATCH/DELETE /registrationappealreview/single/{id} → substituição e
 *   cancelamento de designação (PR6 / issue #40).
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
         *
         * LEITURA RAW OBRIGATÓRIA: `fetch()` só ativa o modo raw com `raw: true`
         * nas options (rawProcessor sozinho é ignorado) — sem isso o payload
         * passa por Entity.populate(), que DESCARTA relações escalares
         * (user:35 vira undefined) e campos computados (resultString).
         *
         * Shapes reais da ApiQuery (evidência de rede): `user` volta ESCALAR
         * mesmo com select aninhado (expansão não suportada nesta entidade);
         * `registration` expande como objeto. Nomes de avaliadores vêm do mapa
         * `evaluators` injetado no init.php, não deste select.
         */
        async fetchSlots() {
            const api = new API('registrationevaluation');
            const evaluations = await api.fetch('find', {
                '@select': 'id,user,status,result,resultString,registration.{id,number}',
                'registration': `EQ(${this.registrationId})`,
                '@order': 'id ASC',
            }, { raw: true, rawProcessor: data => data });

            this.slots = (evaluations || []).map(evaluation => ({
                id: this.normalizeId(evaluation.id),
                user: evaluation.user,
                userId: this.normalizeId(evaluation.user),
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
         * Leitura raw pelo mesmo motivo de fetchSlots: `originalEvaluation`
         * vem ACHATADO para escalar pela ApiQuery (evidência:
         * {"originalEvaluation":1}) e o populate do SDK o descartaria.
         */
        async fetchReviews() {
            if (!this.endpointAvailable) {
                return;
            }

            const api = new API('registrationappealreview');
            const reviews = await api.fetch('find', {
                '@select': 'id,originalEvaluation.id,status,correctionType,endsAt,sentTimestamp,correctorUser',
                'registration': `EQ(${this.registrationId})`,
                '@order': 'id ASC',
            }, { raw: true, rawProcessor: data => data });

            // normalizeId aceita escalar OU objeto — o join com os slots
            // compara ids normalizados nos dois lados. correctorUser (escalar)
            // alimenta o painel e o default da substituição (F5).
            this.reviews = (reviews || []).map(review => ({
                id: this.normalizeId(review.id),
                originalEvaluationId: this.normalizeId(review.originalEvaluation),
                status: review.status,
                correctionType: review.correctionType,
                endsAt: review.endsAt,
                sentTimestamp: review.sentTimestamp,
                correctorUserId: this.normalizeId(review.correctorUser),
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
                review.originalEvaluationId != null
                && review.originalEvaluationId === this.normalizeId(slot.id)
                && active_statuses.includes(this.statusNumber(review))
            ) || null;
        },

        reviewForSlot(slot) {
            return this.reviews.find(review =>
                review.originalEvaluationId != null
                && review.originalEvaluationId === this.normalizeId(slot.id)
            ) || null;
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

        /**
         * Classes utilitárias de cor do tema (0.settings/_atoms.scss) para o
         * rótulo e o ícone de status — mesmo padrão do appeal-phase-chat
         * (mc-icon "circle" + .{primary|success|warning|danger}__color):
         * designado=pendente (primary), rascunho=em andamento (warning),
         * enviado=concluído (success), reaberto=exige ação (danger).
         */
        statusClass(review) {
            return {
                0: 'primary__color',
                1: 'warning__color',
                2: 'success__color',
                3: 'danger__color',
            }[this.statusNumber(review)] || '';
        },

        reviewDeadline(review) {
            return this.formatDate(review?.endsAt);
        },

        reviewSentAt(review) {
            return this.formatDate(review?.sentTimestamp);
        },

        // ============================================================ //
        // F5 (#45): substituição e cancelamento de designação (CA-13)
        // ============================================================ //

        /**
         * Linha "trancada" (visual de inércia, cinza): somente designação
         * ENVIADA — realmente sem ações. Designação ATIVA não usa esse estado:
         * tem ações de gestão e deve parecer viva.
         */
        isSlotLocked(slot) {
            const review = this.reviewForSlot(slot);
            return !!review && !this.canManageReview(review);
        },

        /**
         * Papel do corretor designado para exibição estática na coluna
         * "Corretor designado": dono do slot ou Comissão de Recursos.
         */
        correctorRoleLabel(slot, review) {
            return this.normalizeId(review?.correctorUserId) === this.slotUserId(slot)
                ? this.text('slot owner tag')
                : this.text('committee tag');
        },

        /**
         * Designação ATIVA (status 0/1/3) com API disponível pode ser
         * gerenciada da tela (substituir/cancelar). ENVIADO (2) não tem ações.
         */
        canManageReview(review) {
            if (!this.endpointAvailable || !review) {
                return false;
            }

            const active_statuses = this.config.activeStatuses || [0, 1, 3];
            return active_statuses.includes(this.statusNumber(review));
        },

        /**
         * Nome do corretor atual da designação (mesma resolução de nomes do
         * painel: evaluators → Comissão de Recursos → null).
         */
        reviewCorrectorName(review) {
            return this.evaluatorName(review?.correctorUserId);
        },

        startSubstitution(slot) {
            const review = this.activeReviewForSlot(slot);
            if (!review || !this.canManageReview(review)) {
                return;
            }

            slot.substitutionOpen = true;
            // Default: o corretor atual (PATCH só é enviado se mudar).
            slot.substituteUserId = review.correctorUserId ?? this.slotUserId(slot);
        },

        cancelSubstitution(slot) {
            slot.substitutionOpen = false;
        },

        canConfirmSubstitution(slot) {
            return !slot.substituting
                && this.normalizeId(slot.substituteUserId) != null;
        },

        /**
         * Substitui o corretor da designação ativa do slot:
         * PATCH /registrationappealreview/single/{id} com {correctorUser}.
         * O backend revalida CA-3 (corretor inelegível → 400 com mensagem).
         * Sucesso → mensagem + fetchReviews() reflete o novo corretor sem reload.
         */
        async confirmSubstitution(slot) {
            const review = this.activeReviewForSlot(slot);
            if (!review || !this.canConfirmSubstitution(slot)) {
                return;
            }

            const messages = useMessages();
            slot.substituting = true;

            const api = new API('registrationappealreview');
            const url = Utils.createUrl('registrationappealreview', 'single', { id: review.id });

            try {
                const response = await api.PATCH(url, {
                    correctorUser: this.normalizeId(slot.substituteUserId),
                });

                if (!response.ok) {
                    throw await response.json().catch(() => ({}));
                }

                messages.success(this.text('corrector replaced'));
                slot.substitutionOpen = false;
                await this.fetchReviews();
            } catch (error) {
                console.error('opportunity-appeal-correction-assignment:confirmSubstitution', error);
                messages.error(this.backendErrorMessage(error) || this.text('replace error'));
            } finally {
                slot.substituting = false;
            }
        },

        /**
         * Cancela (DELETE /registrationappealreview/single/{id}) a designação
         * do slot: volta ao estado designável — checkbox destravado pelo
         * recomputo de slotSelectable() após o fetchReviews().
         */
        async cancelAssignment(slot) {
            const review = this.activeReviewForSlot(slot) || this.reviewForSlot(slot);
            if (!review || !this.endpointAvailable) {
                return;
            }

            const messages = useMessages();
            slot.canceling = true;

            const api = new API('registrationappealreview');
            const url = Utils.createUrl('registrationappealreview', 'single', { id: review.id });

            try {
                const response = await api.DELETE(url);

                if (!response.ok) {
                    throw await response.json().catch(() => ({}));
                }

                messages.success(this.text('designation canceled'));
                await this.fetchReviews();
            } catch (error) {
                console.error('opportunity-appeal-correction-assignment:cancelAssignment', error);
                messages.error(this.backendErrorMessage(error) || this.text('cancel designation error'));
            } finally {
                slot.canceling = false;
            }
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
            return slot?.userId ?? this.normalizeId(slot?.user);
        },

        /**
         * Nome do avaliador pelo mapa `evaluators` injetado no init.php —
         * chaveado por opportunityId (mesma estrutura de `committees`):
         * {opportunityId: {userId: name}}. A API de avaliação não expõe
         * nome (relação user não expande). Fallback: Comissão de Recursos
         * da fase de recurso (config.committees, também por oportunidade).
         */
        evaluatorName(userId) {
            if (userId == null) {
                return null;
            }

            const evaluators = this.config.evaluators || {};
            const name = this.opportunityId != null
                ? evaluators[this.opportunityId]?.[userId] ?? null
                : null;

            if (name) {
                return name;
            }

            const committee_member = (this.committee || []).find(member => member.userId === userId);
            return committee_member?.name || null;
        },

        slotAgentName(slot) {
            return this.evaluatorName(this.slotUserId(slot))
                || slot?.agent?.name
                || slot?.user?.profile?.name
                || slot?.user?.name
                || slot?.agent?.email
                || slot?.user?.profile?.email
                || this.text('slot owner tag');
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
                messages.error(this.backendErrorMessage(error) || this.text('save error'));
            } finally {
                this.saving = false;
            }
        },

        /**
         * Extrai a mensagem de erro devolvida pelo backend para exibição.
         *
         * Shapes reais: Controller::errorJson responde
         * {error: true, data: "mensagem"} ou {error: true, data: {campo: msg}}
         * (validação); aceita também {error: "mensagem"} por defesa.
         * Retorna null quando não há texto utilizável (caller usa o genérico).
         */
        backendErrorMessage(error) {
            const candidates = [
                error?.data,
                error?.error,
                error?.data?.error,
            ];

            for (const candidate of candidates) {
                if (typeof candidate === 'string' && candidate.trim()) {
                    return candidate.trim();
                }

                if (candidate && typeof candidate === 'object') {
                    const messages = Object.values(candidate)
                        .flat()
                        .map(value => (typeof value === 'string' ? value.trim() : ''))
                        .filter(Boolean);

                    if (messages.length) {
                        return messages.join(' ');
                    }
                }
            }

            return null;
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

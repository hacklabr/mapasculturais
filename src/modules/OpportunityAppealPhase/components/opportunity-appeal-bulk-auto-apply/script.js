/**
 * opportunity-appeal-bulk-auto-apply (R06 #134 — CA-21, override 2026-11-18)
 *
 * Modal de autoaplicação do resultado em LOTE: lista as designações de
 * correção ELEGÍVEIS da fase de recurso (ativas, oficiais, método
 * não-técnico) e marca autoApply=true nas selecionadas, via endpoint
 * existente POST /registrationappealreview/bulkAutoApply {ids:[...]}.
 *
 * Override do teste manual do PR #137 (2026-11-18): a seleção em lote foi
 * REMOVIDA da tabela de inscrições da fase de recurso (a coluna de seleção
 * do entity-table quebrava o layout — cabeçalho duplicado) e vive neste
 * modal, aberto pelo botão "Autoaplicar nas designações de correção" na
 * área de ações da tabela (ao lado de "Exportar planilha"; rótulo ajustado
 * no QA 2026-11-23 — o lote MARCA a autoaplicação, que aplica o resultado
 * no envio da correção). O modal dá contexto na escolha:
 * inscrição + agente + resultado atual (rótulo do método) + corretor +
 * prazo + status por designação.
 *
 * Abertura (mesma interface pública do opportunity-appeal-correction-
 * assignment — ver docblock do init.php dele):
 *
 *     window.dispatchEvent(new CustomEvent(
 *         'opportunity-appeal-bulk-auto-apply:open',
 *         { detail: { phase: { id: <id da FASE DE RECURSO> } } }
 *     ));
 *
 * A view deve importar e renderizar o componente uma única vez (listener
 * registrado no mounted(), removido no beforeUnmount()).
 *
 * Config consumida (já injetada na página pelos init.php dos componentes
 * opportunity-appeal-correction-assignment e opportunity-registrations-
 * table — este componente não tem init.php próprio):
 * - $MAPAS.config.appealCorrectionAssignment.appealContexts[appealPhaseId]
 *   → {mainPhaseId} (presente somente para gestor com @control na fase
 *   principal — a presença da chave É o gate de permissão);
 * - .mainPhaseMethods[mainPhaseId] → slug do método (botão/modal só
 *   existem fora do técnico — contorno D2);
 * - .evaluators / .committees → nomes de corretores (a relação user não
 *   expande nome na API);
 * - $MAPAS.config.opportunityRegistrationTable.evaluationStatusDict →
 *   rótulos do resultado por método (mesma fonte da coluna "Avaliação");
 *   habilitação documental usa as chaves canônicas valid/invalid do
 *   consolidado (rótulos vindos do valueToString do método — QA 2026-11-23).
 *
 * Backend consumido (somente endpoints existentes, sem mudanças):
 * - GET  /api/registrationappealreview/find?opportunity=…  → listagem por
 *   oportunidade (CA-20/#133), 1 chamada por abertura;
 * - GET  /api/registration/find?id=IN(…)                   → nomes dos
 *   agentes proponentes das inscrições designadas (a expansão aninhada
 *   registration.{owner.name} descarta consolidatedResult na ApiQuery —
 *   evidência de rede — por isso o nome vem em chamada própria);
 * - POST /registrationappealreview/bulkAutoApply            → marcação em
 *   lote (1 transação, relatório {updated, skipped[{id,reason}], counts}).
 */

app.component('opportunity-appeal-bulk-auto-apply', {
    template: $TEMPLATES['opportunity-appeal-bulk-auto-apply'],

    emits: ['saved'],

    setup() {
        // os textos estão localizados no arquivo texts.php deste componente
        const text = Utils.getTexts('opportunity-appeal-bulk-auto-apply');

        return { text };
    },

    data() {
        return {
            appealPhaseId: null,
            loading: false,
            applying: false,
            error: false,
            designations: [],
            report: null,
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
         * Contexto elegível: fase de recurso com gestor @control e método
         * não-técnico (espelha os gates que exibem o botão de abertura —
         * a presença de appealContexts já carrega a checagem de permissão).
         */
        correctionEligible() {
            return this.appealPhaseId != null && this.mainPhaseId != null && this.method !== 'technical';
        },

        mainPhaseId() {
            const appeal_contexts = this.config.appealContexts || {};
            return this.appealPhaseId != null
                ? appeal_contexts[this.appealPhaseId]?.mainPhaseId ?? null
                : null;
        },

        method() {
            const methods = this.config.mainPhaseMethods || {};
            return this.mainPhaseId != null
                ? methods[this.mainPhaseId] ?? null
                : null;
        },

        /**
         * QA 2026-11-23 (#134): o lote só MARCA — designações já marcadas
         * (autoApply=true) ficam fora da seleção (linha visível, informativa,
         * checkbox desabilitado). Desmarcar é pelo modal de designação
         * (PATCH, enquanto a correção não foi enviada).
         */
        selectableDesignations() {
            return this.designations.filter(designation => !designation.autoApply);
        },

        selectedDesignations() {
            return this.designations.filter(designation => designation.checked && !designation.autoApply);
        },

        allSelected() {
            return this.selectableDesignations.length > 0
                && this.selectableDesignations.every(designation => designation.checked);
        },

        canApply() {
            return this.endpointAvailable
                && this.correctionEligible
                && !this.loading
                && !this.applying
                && this.report == null
                && this.selectedDesignations.length > 0;
        },

        /**
         * Cabeçalho do relatório exibido dentro do modal após o lote
         * (contagem de marcadas; ignoradas listadas à parte, com motivo).
         */
        reportSummary() {
            return this.text('report summary').replace('%s', this.report?.marked ?? 0);
        },

        /**
         * Comissão de Recursos da fase principal (nomes de corretor).
         * Precisa ser computed: evaluatorName usa `this.committee` como
         * array — se ficar em methods, `(this.committee || []).find`
         * recebe a function e quebra o loadData do modal (QA 2026-11-23).
         */
        committee() {
            const committees = this.config.committees || {};
            const list = this.mainPhaseId != null
                ? committees[this.mainPhaseId] ?? []
                : [];

            return Array.isArray(list) ? list : [];
        },
    },

    mounted() {
        window.addEventListener('opportunity-appeal-bulk-auto-apply:open', this.handleOpenEvent);
    },

    beforeUnmount() {
        window.removeEventListener('opportunity-appeal-bulk-auto-apply:open', this.handleOpenEvent);
    },

    methods: {
        /**
         * Interface pública: detail = {phase: {id}} (id da fase de recurso).
         */
        handleOpenEvent(event) {
            const { phase } = event?.detail || {};

            const appeal_phase_id = this.normalizeId(phase);

            if (!appeal_phase_id) {
                console.error('opportunity-appeal-bulk-auto-apply: evento requer {phase} com id válido');
                return;
            }

            this.appealPhaseId = appeal_phase_id;
            this.designations = [];
            this.report = null;

            this.$refs.modal?.open();
            this.loadData();
        },

        async loadData() {
            this.loading = true;
            this.error = false;

            try {
                const reviews = await this.fetchDesignations();
                const eligible = this.filterEligible(reviews);
                const agent_names = await this.fetchAgentNames(eligible);

                this.designations = eligible.map(review => ({
                    id: this.normalizeId(review.id),
                    registrationId: this.normalizeId(review.registration),
                    registrationNumber: review.registration?.number || null,
                    agentName: agent_names[this.normalizeId(review.registration)] || null,
                    resultLabel: this.resultLabel(review.registration?.consolidatedResult),
                    correctorName: this.evaluatorName(review.correctorUser),
                    endsAt: review.endsAt || null,
                    status: parseInt(review.status, 10),
                    autoApply: review.autoApply === true || review.autoApply === 'true' || review.autoApply === 1,
                    checked: false,
                }));
            } catch (error) {
                console.error('opportunity-appeal-bulk-auto-apply:loadData', error);
                this.error = true;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Designações da fase de recurso (listagem por oportunidade,
         * CA-20/#133): 1 chamada por abertura, leitura raw (a relação
         * `registration` precisa chegar como objeto expandido).
         */
        async fetchDesignations() {
            const api = new API('registrationappealreview');

            return await api.fetch('find', {
                '@select': 'id,status,correctionType,autoApply,endsAt,correctorUser,registration.{id,number,consolidatedResult}',
                'opportunity': `EQ(${this.appealPhaseId})`,
                '@order': 'id ASC',
            }, { raw: true, rawProcessor: data => data });
        },

        /**
         * Elegíveis para autoaplicação — mesmo critério de skip do
         * POST_bulkAutoApply: designação ATiva (0/1/3) e oficial; o método
         * não-técnico é garantido pelo gate de abertura (per-opportunity).
         */
        filterEligible(reviews) {
            const active_statuses = this.config.activeStatuses || [0, 1, 3];
            const official_type = this.config.correctionTypeDefault || 'official';

            return (reviews || []).filter(review =>
                active_statuses.includes(parseInt(review.status, 10))
                && review.correctionType === official_type
            );
        },

        /**
         * Nomes dos agentes proponentes por id de inscrição. Chamada
         * própria (IN por ids): a expansão aninhada
         * registration.{id,number,consolidatedResult,owner.name} na
         * ApiQuery DESCARTA consolidatedResult (evidência de rede) — duas
         * chamadas de nível único são o caminho com shape garantido.
         */
        async fetchAgentNames(reviews) {
            const names = {};
            const registration_ids = [...new Set(
                (reviews || []).map(review => this.normalizeId(review.registration)).filter(id => id != null)
            )];

            // chunk defensivo: limita o tamanho da query string do IN(...)
            const chunk_size = 100;
            const api = new API('registration');

            for (let i = 0; i < registration_ids.length; i += chunk_size) {
                const chunk = registration_ids.slice(i, i + chunk_size);

                const found = await api.fetch('find', {
                    'id': `IN(${chunk.join(',')})`,
                    '@select': 'id,owner.{name}',
                    '@order': 'id ASC',
                }, { raw: true, rawProcessor: data => data });

                for (const registration of found || []) {
                    names[this.normalizeId(registration)] = registration.owner?.name || null;
                }
            }

            return names;
        },

        /**
         * Resultado atual da inscrição no vocabulário do método da fase
         * principal (mesma fonte de rótulos da coluna "Avaliação" da lista
         * de inscritos — evaluationStatusDict do init da tabela).
         */
        resultLabel(consolidatedResult) {
            if (consolidatedResult === null || consolidatedResult === undefined || consolidatedResult === '') {
                return this.text('result not evaluated');
            }

            const dict = $MAPAS.config.opportunityRegistrationTable?.evaluationStatusDict?.[this.method] || {};
            return dict[String(consolidatedResult)] || String(consolidatedResult);
        },

        /**
         * Nome do corretor (mesma resolução do modal de designação:
         * evaluators da fase principal → Comissão de Recursos → null).
         */
        evaluatorName(userId) {
            const user_id = this.normalizeId(userId);

            if (user_id == null) {
                return null;
            }

            const evaluators = this.config.evaluators || {};
            const name = this.mainPhaseId != null
                ? evaluators[this.mainPhaseId]?.[user_id] ?? null
                : null;

            if (name) {
                return name;
            }

            const committee_member = this.committee.find(member => member.userId === user_id);
            return committee_member?.name || null;
        },

        statusLabel(designation) {
            const status_key = {
                0: 'status designated',
                1: 'status draft',
                2: 'status sent',
                3: 'status reopened',
            }[designation.status];

            return status_key ? this.text(status_key) : String(designation.status ?? '');
        },

        /**
         * Classes utilitárias de cor do tema — mesmo padrão do modal de
         * designação e do appeal-phase-chat.
         */
        statusClass(designation) {
            return {
                0: 'primary__color',
                1: 'warning__color',
                2: 'success__color',
                3: 'danger__color',
            }[designation.status] || '';
        },

        deadline(designation) {
            const value = designation?.endsAt;
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

        /**
         * "Selecionar todos" no padrão da casa (fields-visible-evaluators,
         * seletor de escopo do modal de designação): um checkbox de estado
         * sincronizado com a lista completa. QA 2026-11-23: ignora as
         * designações já marcadas (o lote só marca quem ainda não tem o flag).
         */
        toggleSelectAll() {
            const select = !this.allSelected;

            for (const designation of this.designations) {
                if (designation.autoApply) {
                    continue;
                }

                designation.checked = select;
            }
        },

        /**
         * Marca autoaplicação nas designações selecionadas via endpoint
         * existente POST /registrationappealreview/bulkAutoApply. O lote só
         * MARCA (nunca desmarca); itens fora do domínio voltam no relatório
         * como skipped com motivo, exibido aqui dentro do modal.
         */
        async applyBulkAutoApply() {
            if (!this.canApply) {
                return;
            }

            const messages = useMessages();
            this.applying = true;

            const api = new API('registrationappealreview');
            const url = Utils.createUrl('registrationappealreview', 'bulkAutoApply');
            const ids = this.selectedDesignations.map(designation => designation.id);

            try {
                const response = await api.POST(url, { ids: ids });

                if (!response.ok) {
                    throw await response.json().catch(() => ({}));
                }

                const result = await response.json();

                this.report = {
                    marked: result?.counts?.marked ?? 0,
                    skipped: (result?.skipped || []).map(item => ({
                        id: this.normalizeId(item?.id),
                        reason: String(item?.reason || '-'),
                    })),
                };

                messages.success(this.text('bulk applied'));
                this.$emit('saved', { opportunityId: this.mainPhaseId });

                // Mesma recomposição pós-save do modal de designação:
                // recarrega a própria listagem (flags atualizados) para a
                // próxima interação, mantendo o relatório visível.
                await this.loadData();
            } catch (error) {
                console.error('opportunity-appeal-bulk-auto-apply:applyBulkAutoApply', error);
                messages.error(this.backendErrorMessage(error) || this.text('bulk error'));
            } finally {
                this.applying = false;
            }
        },

        /**
         * Linha do relatório para uma designação ignorada (inscrição +
         * motivo), quando o id ainda está na listagem carregada.
         */
        skippedLabel(item) {
            const designation = this.designations.find(entry => entry.id === item.id);
            const number = designation?.registrationNumber || `#${item.id}`;

            return `${number} — ${item.reason}`;
        },

        /**
         * Extrai a mensagem de erro devolvida pelo backend (mesmo shapes do
         * Controller::errorJson tratados pelo modal de designação).
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
                    const error_messages = Object.values(candidate)
                        .flat()
                        .map(value => (typeof value === 'string' ? value.trim() : ''))
                        .filter(Boolean);

                    if (error_messages.length) {
                        return error_messages.join(' ');
                    }
                }
            }

            return null;
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

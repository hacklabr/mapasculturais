app.component('opportunity-phase-publish-config-registration' , {
    template: $TEMPLATES['opportunity-phase-publish-config-registration'],

    setup() {
        const text = Utils.getTexts('opportunity-phase-publish-config-registration');
        return { text };
    },

    props: {
        phase: {
            type: Entity,
            required: true
        },
        phases: {
            type: Array,
            required: true
        },
        hideButton: {
            type: Boolean,
            default: false
        },
        hideDatepicker: {
            type: Boolean,
            default: false
        },
        hideCheckbox: {
            type: Boolean,
            default: false
        },
        hideDescription: {
            type: Boolean,
            default: false
        },
        tab: {
            type: String,
        },
        /**
         * Guard de instância (R02/#65 — risco D3): este componente também é
         * instanciado para a fase de recurso, via opportunity-appeal-phase-config
         * (-> opportunity-phase-status e -> opportunity-phase-list-evaluation).
         * R02: o botão "Publicar resultado final" (sempre disponível) só devia
         * existir na instância da fase principal; o contexto de recurso repassa
         * false. R03 (#76): com false, o dois-estágios fica oculto e entram os
         * botões próprios do recurso ("Publicar/Despublicar resultado do
         * recurso" — mesmas ações do final, copy próprio).
         */
        mainPhaseOnly: {
            type: Boolean,
            default: true
        },
    },

    computed: {
        isOpenPhase() {
            if(this.phase?.evaluationMethodConfiguration) {
                return this.phase?.evaluationMethodConfiguration?.evaluationTo?.isFuture();
            }

            return this.phase?.registrationTo?.isFuture();

        },

        /*
            R02 (#72): a fase terminou? Sem registrationTo definido → true
            (comportamento atual — botão disponível). Parsing pelo McDate da
            própria prop (DateTime serializado {date, timezone}; isPast()
            compara no timezone do objeto, não do browser — mesmo padrão das
            janelas da fase de recurso no registration-status).
        */
        phaseEnded() {
            if (!this.phase?.registrationTo) {
                return true;
            }

            return this.phase.registrationTo.isPast();
        },

        minDate () {
            return this.phase.evaluationTo?._date || this.phase.registrationTo?._date;
        },
        maxDate () {
            if(!this.phase.isLastPhase) {
                return this.lastPhase?.publishTimestamp?._date;
            }
        },
        firstPhase() {
            const firstPhase = this.phases[0];
            if (firstPhase.isFirstPhase) {
                return firstPhase;
            }
        },
        lastPhase() {
            const lastPhase = this.phases[this.phases.length - 1];
            if (lastPhase.isLastPhase) {
                return lastPhase;
            }
        },
        isPublished() {
            return this.firstPhase.status > 0;
        },
        /**
         * Segunda camada do guard (dados da entidade): quando o metadado
         * isAppealPhase está presente na fase, a publicação final fica
         * bloqueada mesmo que a prop mainPhaseOnly não seja repassada.
         */
        isAppealPhase() {
            return !!this.phase?.isAppealPhase;
        },
        showFinalPublishButton() {
            return this.mainPhaseOnly && !this.isAppealPhase;
        },
        /**
         * R03 (#76): instância da fase de recurso — o opportunity-appeal-phase-config
         * repassa main-phase-only=false aos intermediários (opportunity-phase-status /
         * opportunity-phase-list-evaluation). Distinto do computed isAppealPhase
         * (guard por metadado da entidade, que não vem serializado no payload de
         * fases): aqui o marcador de contexto é a própria prop.
         */
        isAppealPhaseInstance() {
            return !this.mainPhaseOnly;
        },
        /**
         * R03 (#76): visibilidade do container de ações. Instância principal:
         * inalterada (R02/#65). Instância da fase de recurso: botões somente
         * com a fase terminada (publicar — gate phaseEnded do #72) ou já
         * publicada (despublicar); antes do término, nenhum botão renderiza.
         */
        showActionsContainer() {
            if (this.isAppealPhaseInstance) {
                return this.phaseEnded || !!this.phase.publishedRegistrations;
            }

            return !this.phase.publishedRegistrations || this.showFinalPublishButton;
        },
    },

    methods: {
        publishPreliminaryRegistration () {
            const messages = useMessages();
            this.phase.POST('publishPreliminaryRegistrations', this.phase).then(item => {
                this.phase.publishedPreliminaryRegistrations = true;
                messages.success(this.text('sucesso_publicar_preliminar'));
            });
        },
        unpublishPreliminaryRegistration () {
            const messages = useMessages();
            this.phase.POST('unPublishPreliminaryRegistrations', this.phase).then(item => {
                this.phase.publishedPreliminaryRegistrations = false;
                messages.success(this.text('sucesso_despublicar_preliminar'));
            });
        },
        publishRegistration () {
            const messages = useMessages();
            this.phase.POST('publishRegistrations', this.phase).then(item => {
                this.phase.publishedRegistrations = true;
                messages.success(this.text('sucesso_publicar_final'));
            });
        },
        unpublishRegistration () {
            const messages = useMessages();
            this.phase.POST('unpublishRegistrations', this.phase).then(item => {
                this.phase.publishedRegistrations = false;
                messages.success(this.text('sucesso_despublicar_final'));
            });
        },
        /**
         * R03 (#76): mesma ação do publicar final (POST publishRegistrations —
         * publica com selos o resultado da fase de recurso), com mensagem de
         * sucesso própria do contexto de recurso.
         */
        publishAppealRegistration () {
            const messages = useMessages();
            this.phase.POST('publishRegistrations', this.phase).then(item => {
                this.phase.publishedRegistrations = true;
                messages.success(this.text('sucesso_publicar_recurso'));
            });
        },
        /**
         * R03 (#76): mesma ação do despublicar final (POST unpublishRegistrations),
         * com mensagem de sucesso própria do contexto de recurso.
         */
        unpublishAppealRegistration () {
            const messages = useMessages();
            this.phase.POST('unpublishRegistrations', this.phase).then(item => {
                this.phase.publishedRegistrations = false;
                messages.success(this.text('sucesso_despublicar_recurso'));
            });
        }
    }
});
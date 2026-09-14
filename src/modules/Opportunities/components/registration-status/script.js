app.component('registration-status', {
    template: $TEMPLATES['registration-status'],

    props: {
        registration: {
            type: Entity,
            required: true
        },

        phase: {
            type: Entity,
            required: true
        }
    },
    
    setup(props, { slots }) {
        const hasSlot = name => !!slots[name];
        // os textos estão localizados no arquivo texts.php deste componente 
        const text = Utils.getTexts('registration-status');
        return { text, hasSlot }
    },

    data() {
        return {
            processing: false,
            entity: null,
            // F8 (#21): notas do fluxo preliminar → recurso → final do próprio
            // proponente (buscadas só quando publicado; leitura raw).
            flowScores: null,
        }
    },

    mounted() {
        this.loadAppealFlowScores();
    },

    computed: {
        firstPhase() {
            return this.opportunity.parent || this.opportunity;
        },
        firstPhaseRegistration() {
            return $MAPAS.registrationPhases[this.firstPhase.id];
        },
        appealPhase() {
            return this.opportunity.isAppealPhase ? this.opportunity : this.opportunity.appealPhase;
        },

        appealRegistration() {
            const appealPhaseId = this.appealPhase?.id;
            if (!appealPhaseId) {
                return null;
            }

            return $MAPAS.registrationPhases[appealPhaseId] || this.entity;
        },

        /*
         * F8 (#21) — fluxo preliminar → recurso → reavaliação → final.
         * Gates espelham os server-side do PR3 (Opportunity::
         * areRegistrationResultsPublished): o que não está publicado não é
         * buscado nem exibido (critério de privacidade 5).
         */
        preliminaryPublished() {
            return !!(this.opportunity.publishedRegistrations || this.opportunity.publishedPreliminaryRegistrations);
        },

        finalPublished() {
            return !!this.opportunity.publishedRegistrations;
        },

        showAppealFlow() {
            return !!this.appealPhase
                && !this.opportunity.isAppealPhase
                && (this.preliminaryPublished || !!this.appealRegistration?.id);
        },

        flowPreliminaryScore() {
            return this.flowScores?.averageOriginalScore ?? null;
        },

        flowCorrectedScore() {
            return this.flowScores?.averageCorrectedScore ?? null;
        },

        flowHasCorrection() {
            return this.flowScores !== null
                && this.flowScores.scoreDifference !== null
                && this.flowScores.scoreDifference !== 0;
        },

        appealFlowStatusLabel() {
            const appeal_registration = this.appealRegistration;
            if (!appeal_registration?.id) {
                return '';
            }

            if (appeal_registration.status == 0) {
                return this.text('flow draft');
            }

            if (appeal_registration.status == 1) {
                return this.text('flow sent awaiting');
            }

            // Veredito (deferido/indeferido/...) só quando o método expõe ao
            // dono — mesmo gate server-side do bloco [Recurso] existente.
            return this.shouldDisplayEvaluationResults(appeal_registration)
                ? (this.appealPhase?.statusLabels?.[appeal_registration.status] ?? this.text('flow under analysis'))
                : this.text('flow under analysis');
        },

        canShowAppeal() {
            if (this.registration.opportunity.isReportingPhase) {
                return false;
            }

            if(!this.firstPhaseRegistration?.currentUserPermissions.create) {
                return false;
            }

            if(this.registration.opportunity.appealPhase && (this.appealRegistration || this?.registration?.opportunity?.appealPhase?.registrationFrom?.isFuture() || this?.registration?.opportunity?.appealPhase?.registrationTo?.isPast())) {
                return false;
            }


            return this.registration.status > 1 && this.registration.status <= 10;
        },

        opportunity () {
            if (this.phase.__objectType === 'evaluationmethodconfiguration') {
                return this.phase.opportunity;
            } else {
                return this.phase;
            }
        },

        showRegistrationResults() {
            const { isReportingPhase, __objectType, publishEvaluationDetails } = this.phase;
            const { allow_proponent_response } = this.registration.opportunity;

            if (isReportingPhase === '1' && __objectType === 'opportunity' && allow_proponent_response) {
                return false;
            }

            return publishEvaluationDetails || allow_proponent_response;
        },

        statuses() {
            return this.registration.opportunity.statusLabels;
        }
    },

    methods: {
        /**
         * F8 (#21): notas do fluxo (médias original/corrigida da própria
         * inscrição — propriedades computadas do módulo OpportunityAppealPhase,
         * expostas ao dono pela API de registration).
         *
         * Privacidade: busca SÓ quando há resultado publicado (preliminar ou
         * final — o mesmo gate server-side do status); leitura raw porque o
         * populate do SDK descarta as chaves virtuais. Erros deixam o fluxo
         * sem notas (passos exibem "—"), nunca bloqueiam a página.
         */
        async loadAppealFlowScores() {
            if (!this.showAppealFlow || !this.preliminaryPublished || !this.registration?.id) {
                return;
            }

            try {
                const api = new API('registration');
                const rows = await api.fetch('find', {
                    '@select': 'id,averageOriginalScore,averageCorrectedScore,scoreDifference',
                    'id': `EQ(${this.registration.id})`,
                }, { raw: true, rawProcessor: data => data });

                this.flowScores = rows?.[0] || null;
            } catch (error) {
                console.error('registration-status:loadAppealFlowScores', error);
                this.flowScores = null;
            }
        },

        flowScoreOrDash(value) {
            return (value === null || value === undefined) ? '—' : this.formatNote(value);
        },

        showPhaseDates() {
            const firstPhase = $MAPAS.opportunityPhases?.find((phase) => phase.isFirstPhase) || this.firstPhase;
            return !firstPhase?.hidePhaseDates;
        },

        shouldDisplayEvaluationResults(registration) {
            return $MAPAS.config.registrationResults.shouldDisplayEvaluationResults[registration.id];
        },
		formatNote(note) {
			note = parseFloat(note);
			return note.toLocaleString($MAPAS.config.locale);
		},
        /**
         * Retorna o status no formato esperado por <mc-status>,
         * respeitando a legenda oficial de cores da inscrição:
         * 0 Rascunho (preto), 1 Pendente (preto), 2 Inválida (roxo),
         * 3 Não selecionada (vermelho), 8 Suplente (laranja), 10 Selecionada (verde).
         */
        getStatusDisplay(registration) {
            let status = registration.status;
            if (registration.opportunity?.isAppealPhase) {
                status = this.shouldDisplayEvaluationResults(registration) ? status : 1;
            }

            return {
                key: status,
                value: status,
                label: this.showRegistrationStatus(registration),
            };
        },

        async createAppealPhaseRegistration() {
            this.processing = true;
            const messages = useMessages();
        
            const target = this.opportunity;

            const args = {
                registration_id: this.registration._id,
            };

            try {
                await target.POST('createAppealPhaseRegistration', {data: args, callback: (data) => {
                        this.entity = new Entity('registration');
                        this.entity.populate(data);
                        this.processing = false;
                        messages.success(this.text('Solicitação de recurso criada com sucesso'));

                        window.location.href = Utils.createUrl('registration', 'view', [this.entity.id]);
                }});
                    
            } catch (error) {
                console.error(error);
                messages.error(error.data ?? error);
            }
            this.processing = false;
        },

        fillFormButton() {
            window.location.href = this.appealRegistration.editUrl;
        },

        dateFrom() {
			if (this.appealPhase?.registrationFrom) {
				return this.appealPhase?.registrationFrom.date('2-digit year');
			}

			if (this.appealPhase?.evaluationMethodConfiguration?.evaluationFrom) {
				return this.appealPhase?.evaluationMethodConfiguration?.evaluationFrom.date('2-digit year');
			}
			return false;
		},

		dateTo() {
			if (this.appealPhase?.registrationTo) {
				return this.appealPhase?.registrationTo?.date('2-digit year');
			}

			if (this.appealPhase?.evaluationMethodConfiguration?.evaluationTo) {
				return this.appealPhase?.evaluationMethodConfiguration?.evaluationTo?.date('2-digit year');
			}
			return false;
		},

		hour() {
			if (this.appealPhase?.registrationTo) {
				return this.appealPhase?.registrationTo?.time();
			}
			if (this.appealPhase?.evaluationMethodConfiguration?.evaluationTo) {
				return this.appealPhase?.evaluationMethodConfiguration?.evaluationTo.time();
			}
			return false;
		},

        redirectToRegistrationForm() {
            return window.location.hash = "#ficha";
        },
        
        shouldShowResults(item) {
			// se é uma fase de avaliação que não tem uma fase de coleta de dados anterior
			const isEvaluation = item.__objectType == 'evaluationmethodconfiguration';

			// se é uma fase de coleta de dados que não tem uma fase de avaliação posterior
			const isRegistrationOnly = item.__objectType == 'opportunity' && !item.evaluationMethodConfiguration;

			const phaseOpportunity = item.__objectType == 'opportunity' ? item : item.opportunity;

			return phaseOpportunity.publishedRegistrations && (isRegistrationOnly || isEvaluation);
		
		},
        showResults(phase) {
            const types = ['qualification', 'technical', 'documentary'];
            return types.includes(phase.type) || phase.publishEvaluationDetails;
        },

        showRegistrationStatus(registration) {
            if(registration.opportunity?.isReportingPhase) {
               return this.phase.opportunity.statusLabels[registration.status];
            }
            
            if(registration.opportunity.isAppealPhase) {
                return this.shouldDisplayEvaluationResults(registration) ? this.phase.appealPhase.statusLabels[registration.status] : this.phase.appealPhase.statusLabels[1];
            }

            if(registration.status == 0) {
                return this.statuses[registration.status] || this.text('Não enviada');
            }

            if(registration.status == 1) {
                return this.statuses[registration.status] || this.text('Enviada');
            }

            return this.statuses[registration.status];
        }
    }
});

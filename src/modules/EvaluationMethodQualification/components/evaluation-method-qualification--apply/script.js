app.component('evaluation-method-qualification--apply', {
    template: $TEMPLATES['evaluation-method-qualification--apply'],

    setup() {
        // os textos estão localizados no arquivo texts.php deste componente
        const text = Utils.getTexts('evaluation-method-qualification--apply')
        return { text }
    },

    props: {
        entity: {
            type: Entity,
            required: true
        },

        entities: {
            type: Array,
            required: true
        }
    },

    data() {
        return {
            applyData: {},
            applyAll: false,
            registrationListText: '',
            tabSelected: 'result'
        };
    },

    computed: {
        statusList() {
            return $MAPAS.config['evaluation-method-qualification--apply'].statusList;
        },
        consolidatedResults() {
            return $MAPAS.config['evaluation-method-qualification--apply'].consolidated_results;
        },
        defaultStatusByResult() {
            return $MAPAS.config['evaluation-method-qualification--apply'].defaultStatusByResult;
        }
    },

    watch: {
        'applyData.from'(value) {
            const defaultStatus = this.defaultStatusByResult[value];

            if (defaultStatus !== undefined) {
                this.applyData.to = defaultStatus;
            }
        }
    },

    methods: {
        apply(modal) {
            const messages = useMessages();

            this.prepareApplyData();
            this.entity.disableMessages();

            this.entity.POST('applyEvaluationsQualification', {
                data: this.applyData, callback: data => {
                    messages.success(data);
                    modal.close();
                    this.reloadPage();
                    this.entity.enableMessages();
                }
            }).catch((data) => {
                messages.error(data.data)
            });
        },
        changed(event) {
            this.tabSelected = event.tab.slug;
        },
        modalClose() {
            this.resetApplyData();
        },
        parseRegistrationList(text) {
            if (!text || !text.trim()) {
                return [];
            }

            return text
                .split(/[,;\s\n\r]+/)
                .map(number => number.trim())
                .filter(number => number.length > 0);
        },
        prepareApplyData() {
            this.applyData.tabSelected = this.tabSelected;

            if (this.tabSelected === 'registration') {
                this.applyData.registrationNumbers = this.parseRegistrationList(this.registrationListText);
                delete this.applyData.from;
                delete this.applyData.status;
                return;
            }

            this.applyData.status = this.applyAll ? 'all' : 'pending';
            delete this.applyData.registrationNumbers;
        },
        resetApplyData() {
            this.applyData = {};
            this.applyAll = false;
            this.registrationListText = '';
            this.tabSelected = 'result';
        },
        valueToString(value) {
            switch (value) {
                case 'valid':
                    return this.text('Habilitado');
                case 'invalid':
                    return this.text('Inabilitado');
                case '0':
                    return this.text('Não avaliada');
                default:
                    return value || '';
            }
        },
        reloadPage(timeout = 1500) {
            this.entities.refresh();
        }
    },
});

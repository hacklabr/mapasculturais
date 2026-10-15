app.component('panel--appeal-correction-assignments', {
    template: $TEMPLATES['panel--appeal-correction-assignments'],

    setup() {
        const text = Utils.getTexts('panel--appeal-correction-assignments');

        return { text };
    },

    data() {
        return {
            loading: true,
            error: false,
            assignments: [],
        };
    },

    mounted() {
        this.fetchAssignments();
    },

    methods: {
        async fetchAssignments() {
            this.loading = true;
            this.error = false;

            try {
                const api = new API();
                const res = await api.GET(Utils.createUrl('appealCorrector', 'assignments'));

                if (res.ok) {
                    this.assignments = await res.json();
                } else {
                    this.error = true;
                }
            } catch (e) {
                this.error = true;
            } finally {
                this.loading = false;
            }
        },

        formatDate(value) {
            return value ? new Date(value).toLocaleString('pt-BR') : '';
        },

        statusLabel(status) {
            return this.text(`status-${status}`) || status;
        },

        unavailableLabel(assignment) {
            if (assignment.startsAt && new Date(assignment.startsAt) > new Date()) {
                return this.text('not-started');
            }

            return this.text('expired');
        },
    },
});

app.component('search-filter-agent', {
    template: $TEMPLATES['search-filter-agent'],

    setup() { 
        // os textos estão localizados no arquivo texts.php deste componente 
        const text = Utils.getTexts('search-filter-agent')
        return { text }
    },
    created() {
        this.pseudoQuery['term:area'] = [];
    },

    props: {
        position: {
            type: String,
            default: 'list'
        },
        pseudoQuery: {
            type: Object,
            required: true
        }
    },

    data() {
        return {
            terms: $TAXONOMIES.area.terms,
        }
    },

    computed: {
    },
    
    methods: {
    },
});
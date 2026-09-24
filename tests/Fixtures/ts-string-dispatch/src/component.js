import { mapActions, mapGetters } from 'vuex';

export default {
    computed: { ...mapGetters('cookie', ['internal']) },
    methods: {
        ...mapActions({ tickets: 'cookie/setAllTickets' }),
        toggle() {
            this.$store.dispatch('cookie/setInternal', true);
        },
    },
};

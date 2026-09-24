export const actions = {
    setInternal({ commit }, internal) {
        commit('SET_INTERNAL', internal);
    },
    setAllTickets({ dispatch }, all) {
        dispatch('refresh', all);
    },
    refresh() {},
    unused() {},
};

export const mutations = {
    SET_INTERNAL(state, internal) {
        state.internal = internal;
    },
};

export const getters = {
    internal: (state) => state.internal,
};

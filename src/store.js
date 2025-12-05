export const Store = {
    state: {
        token: localStorage.getItem('token') || null,
        user: null,
        currentProject: null,
        currentSession: null,
        projects: [],
        sessions: [],
        chatHistory: [],
        isLoading: false
    },

    // Prosty setter dla zachowania czystości
    set(key, value) {
        this.state[key] = value;
        // Tu w przyszłości dodamy reaktywność (np. odświeżanie UI przy zmianie danych)
    },
    
    get(key) {
        return this.state[key];
    }
};
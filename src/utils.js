export const Utils = {
    // Proste formatowanie daty
    formatDate: (dateString) => {
        if (!dateString) return '';
        return new Date(dateString).toLocaleString('pl-PL', {
            month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
        });
    },

    // Symulacja opóźnienia (dla UX, żeby user widział że coś się dzieje)
    sleep: (ms) => new Promise(resolve => setTimeout(resolve, ms)),

    // Generowanie prostego ID (jeśli potrzebne frontendowo)
    uid: () => Date.now().toString(36) + Math.random().toString(36).substr(2)
};
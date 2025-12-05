// Bezpieczne ładowanie zależności
const marked = window.marked || { parse: (t) => t };
const DOMPurify = window.DOMPurify || { sanitize: (t) => t };

export const Render = {
    // Prosta funkcja renderująca Markdown
    markdown: (text) => {
        if (!text) return '';
        try {
            // Zamiana MD na HTML
            const rawHtml = marked.parse(text);
            // Czyszczenie HTML (Security)
            return DOMPurify.sanitize(rawHtml);
        } catch (e) {
            console.error("Render Error:", e);
            return text; // W razie awarii zwróć czysty tekst
        }
    },

    // PUSTA funkcja highlight (żeby Chat.js nie wyrzucał błędu, że funkcja nie istnieje)
    highlightBlock: (element) => {
        // Funkcja wyłączona dla stabilności. 
        // Jeśli chcesz przywrócić kolory, upewnij się że highlight.js ładuje się w <head>
        return; 
    },

    userMessage: (text) => {
        if (!text) return '';
        const div = document.createElement('div');
        div.innerText = text;
        return div.innerHTML;
    }
};
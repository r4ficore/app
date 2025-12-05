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

    highlightBlock: (element) => {
        if (!element) return;
        try {
            if (!window.hljs || !window.hljs.highlightElement) return;
            const blocks = element.querySelectorAll('pre code');
            if (!blocks || blocks.length === 0) return;
            blocks.forEach((block) => {
                try {
                    window.hljs.highlightElement(block);
                } catch (e) {
                    console.warn('Highlight error on block', e);
                }
            });
        } catch (e) {
            console.warn('Highlight error', e);
        }
    },

    userMessage: (text) => {
        if (!text) return '';
        const div = document.createElement('div');
        div.innerText = text;
        return div.innerHTML;
    }
};
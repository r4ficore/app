import { CONFIG } from '../config.js';
import { Store } from '../store.js';
import { Render } from '../ui/Render.js';
import { Toasts } from '../ui/Toasts.js';
import { Modal } from '../ui/Modal.js';

export const Chat = {
    // Stan wyszukiwania (domyślnie wyłączony)
    isSearchActive: false,
    MAX_FILE_SIZE: 2 * 1024 * 1024,
    ALLOWED_TYPES: ['text/plain', 'text/markdown', 'text/x-markdown', 'application/json', 'text/csv'],

    isFileAllowed(file) {
        if (!file) return true;
        if (file.size > Chat.MAX_FILE_SIZE) return false;
        if (!file.type) return true; // Niektóre przeglądarki nie podają typu; waliduje backend
        return Chat.ALLOWED_TYPES.includes(file.type);
    },

    // NOWA FUNKCJA: Przełącznik
    toggleSearch: () => {
        Chat.isSearchActive = !Chat.isSearchActive;
        const btn = document.getElementById('search-toggle');
        
        if (Chat.isSearchActive) {
            // Włączony: Niebieski + Świecenie
            btn.className = "p-3 text-blue-400 drop-shadow-[0_0_8px_rgba(96,165,250,0.8)] transition flex-shrink-0 animate-pulse";
            Toasts.show('Tryb Online: WŁĄCZONY');
        } else {
            // Wyłączony: Szary
            btn.className = "p-3 text-gray-600 hover:text-blue-400 transition flex-shrink-0";
            Toasts.show('Tryb Online: WYŁĄCZONY');
        }
    },

    loadSessions: async () => { /* ... bez zmian ... */
        const list = document.getElementById('sessions-list');
        if (!list) return;
        list.innerHTML = '<div class="p-4 text-xs text-gray-500 animate-pulse">Ładowanie...</div>';
        try {
            const res = await fetch(`${CONFIG.API_URL}?action=get_sessions&project_id=${Store.get('currentProject')}`, { headers: {'Authorization': Store.get('token')} });
            const d = await res.json();
            Store.set('sessions', d.sessions);
            list.innerHTML = '';
            if (!d.sessions || d.sessions.length === 0) { list.innerHTML = '<div class="p-4 text-[10px] text-gray-600 text-center">Brak historii</div>'; return; }
            d.sessions.forEach(s => {
                const div = document.createElement('div');
                const isActive = Store.get('currentSession') === s.id;
                div.className = `group flex justify-between items-center p-3 rounded-lg cursor-pointer transition text-xs mb-1 ${isActive ? 'active-session' : 'text-gray-400 hover:bg-white/5 hover:text-gray-200'}`;
                div.innerHTML = `<span class="truncate pr-2 pointer-events-none">${s.title}</span><button class="del-btn opacity-0 group-hover:opacity-100 text-gray-600 hover:text-red-400 font-bold px-2 transition">×</button>`;
                div.onclick = () => Chat.loadHistory(s.id, s.title);
                div.querySelector('.del-btn').onclick = (e) => { e.stopPropagation(); Chat.deleteSession(s.id); };
                list.appendChild(div);
            });
        } catch (e) { list.innerHTML = '<div class="p-2 text-red-500 text-xs">Błąd sesji</div>'; }
    },

    loadHistory: async (sid, title) => { /* ... bez zmian ... */
        Store.set('currentSession', sid);
        const titleEl = document.getElementById('current-chat-title');
        if (titleEl) titleEl.innerText = title;
        Chat.loadSessions(); 
        const box = document.getElementById('chat-box');
        if (box) box.innerHTML = ''; 
        try {
            const res = await fetch(`${CONFIG.API_URL}?action=get_chat_history&session_id=${sid}`, { headers: {'Authorization': Store.get('token')} });
            const d = await res.json();
            Store.set('chatHistory', d.history);
            if(d.history && Array.isArray(d.history)) {
                d.history.forEach(msg => {
                    const bubble = Chat.renderMessage(msg.role, msg.content);
                    if (msg.role === 'assistant' && Render.highlightBlock) Render.highlightBlock(bubble);
                });
            }
            if(box) box.scrollTop = box.scrollHeight;
        } catch(e) { console.error(e); }
    },

    startNew: () => { /* ... bez zmian ... */
        Store.set('currentSession', null);
        Store.set('chatHistory', []);
        const titleEl = document.getElementById('current-chat-title');
        if (titleEl) titleEl.innerText = "Nowa rozmowa";
        const box = document.getElementById('chat-box');
        if (box) box.innerHTML = `<div class="flex flex-col items-center justify-center h-full text-gray-700 space-y-4 opacity-60"><div class="text-4xl animate-pulse">✨</div><p class="text-xs tracking-wide">SYSTEM GOTOWY</p></div>`;
        Chat.loadSessions();
    },

    deleteSession: async (sid) => { /* ... bez zmian ... */
        if (await Modal.open('Usunąć tę rozmowę?', 'confirm')) {
            await fetch(`${CONFIG.API_URL}?action=delete_session`, {
                method: 'POST', headers: {'Authorization': Store.get('token')}, body: JSON.stringify({session_id: sid, project_id: Store.get('currentProject')})
            });
            if (Store.get('currentSession') === sid) Chat.startNew();
            Chat.loadSessions();
        }
    },

    renderMessage: (role, text) => { /* ... bez zmian ... */
        const box = document.getElementById('chat-box');
        if (!box) return null;
        if (box.querySelector('.flex-col')) box.innerHTML = ''; 
        const div = document.createElement('div');
        div.className = `flex w-full mb-6 ${role === 'user' ? 'justify-end' : 'justify-start'}`;
        const contentHtml = role === 'user' ? Render.userMessage(text) : Render.markdown(text);
        const bubbleClass = role === 'user' ? 'bg-blue-600 text-white px-5 py-3 rounded-2xl rounded-tr-sm max-w-[85%] text-sm shadow-lg shadow-blue-900/20 leading-relaxed' : 'glass-panel px-6 py-5 rounded-2xl rounded-tl-sm max-w-[90%] prose prose-invert text-sm shadow-xl border-white/5';
        div.innerHTML = `<div class="${bubbleClass}">${contentHtml}</div>`;
        box.appendChild(div);
        return div.querySelector('div');
    },

    handleFile: (input) => { /* ... bez zmian ... */
        if (input.files[0]) {
            if (!Chat.isFileAllowed(input.files[0])) {
                Toasts.show('Plik jest zbyt duży lub ma nieobsługiwany format (dozwolone: TXT/MD/JSON/CSV, max 2MB).', 'error');
                Chat.clearFile();
                return;
            }
            const preview = document.getElementById('file-preview');
            const nameEl = document.getElementById('filename');
            if(preview && nameEl) { preview.classList.remove('hidden'); nameEl.innerText = input.files[0].name; }
        }
    },

    clearFile: () => { /* ... bez zmian ... */
        const inp = document.getElementById('file-input');
        const preview = document.getElementById('file-preview');
        if(inp) inp.value = '';
        if(preview) preview.classList.add('hidden');
    },

    sendMessage: async (e) => {
        e.preventDefault();
        const inputEl = document.getElementById('msg-input');
        const fileEl = document.getElementById('file-input');
        const txt = inputEl ? inputEl.value.trim() : '';
        const file = fileEl ? fileEl.files[0] : null;

        if (!txt && !file) return;

        if (file && !Chat.isFileAllowed(file)) {
            Toasts.show('Plik jest zbyt duży lub ma nieobsługiwany format (dozwolone: TXT/MD/JSON/CSV, max 2MB).', 'error');
            Chat.clearFile();
            return;
        }

        Chat.renderMessage('user', txt + (file ? ` [Plik: ${file.name}]` : ''));
        if(inputEl) inputEl.value = '';
        Chat.clearFile();
        
        const formData = new FormData();
        formData.append('message', txt);
        formData.append('token', Store.get('token'));
        formData.append('project_id', Store.get('currentProject'));
        if (Store.get('currentSession')) formData.append('session_id', Store.get('currentSession'));
        if (file) formData.append('file', file);
        
        // NOWOŚĆ: Przekazujemy stan przycisku (1 lub 0)
        formData.append('use_search', Chat.isSearchActive ? '1' : '0');

        // OPCJONALNE: Reset przycisku po wysłaniu? (Na razie zostawiamy, użytkownik decyduje)
        // Chat.toggleSearch();

        const botBubble = Chat.renderMessage('assistant', '<span class="animate-pulse">Analizuję...</span>');
        let botText = "";
        let searchNotices = "";
        const box = document.getElementById('chat-box');
        const isSmartScroll = () => box ? (box.scrollHeight - box.scrollTop) <= (box.clientHeight + 150) : false;

        try {
            const res = await fetch(`${CONFIG.API_URL}?action=chat`, { method: 'POST', body: formData });
            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            let halted = false;
            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                const chunk = decoder.decode(value);
                const lines = chunk.split('\n');
                for (const line of lines) {
                    if (!line.trim()) continue;
                    try {
                        const d = JSON.parse(line);
                        if (d.status === 'session_init') { Store.set('currentSession', d.id); Chat.loadSessions(); }
                        else if (d.status === 'content') {
                            const shouldScroll = isSmartScroll();
                            botText += d.text;
                            if (botBubble) {
                                botBubble.innerHTML = Render.markdown(searchNotices + botText);
                                if (botText.includes('```') && Render.highlightBlock) Render.highlightBlock(botBubble);
                            }
                            if (shouldScroll && box) box.scrollTop = box.scrollHeight;
                        }
                        else if (d.status === 'searching') {
                            searchNotices += `> 🔍 ${d.msg || 'Przeszukuję Internet...'}\n\n`;
                            if (botBubble) botBubble.innerHTML = Render.markdown(searchNotices + botText || '<span class="animate-pulse">Analizuję...</span>');
                        }
                        else if (d.status === 'search_error' || d.status === 'scrape_error') {
                            searchNotices += `> ⚠️ ${d.msg || 'Błąd wyszukiwania.'}\n\n`;
                            if (botBubble) botBubble.innerHTML = Render.markdown(searchNotices + botText || '<span class="animate-pulse">Analizuję...</span>');
                        }
                        else if (d.status === 'file_error') {
                            halted = true;
                            const warn = d.msg || 'Plik został odrzucony (format/rozmiar).';
                            searchNotices += `> ⚠️ ${warn}\n\n`;
                            if (botBubble) botBubble.innerHTML = Render.markdown(searchNotices || warn);
                            Toasts.show(warn, 'error');
                            break;
                        }
                    } catch (e) { }
                }
                if (halted) break;
            }
            if (botBubble && Render.highlightBlock) Render.highlightBlock(botBubble);
            if (box) box.scrollTop = box.scrollHeight;
        } catch (e) {
            if (botBubble) botBubble.innerHTML = '<span class="text-red-400">Błąd połączenia.</span>';
        }
    },

    download: async () => {
        const sid = Store.get('currentSession');
        if (!sid) { Toasts.show('Brak rozmowy do pobrania.', 'error'); return; }

        try {
            const res = await fetch(`${CONFIG.API_URL}?action=get_chat_history&session_id=${sid}`, { headers: {'Authorization': Store.get('token')} });
            if (!res.ok) throw new Error('Nie udało się pobrać historii.');
            const d = await res.json();
            const history = d.history || [];
            if (!history.length) { Toasts.show('Historia jest pusta.', 'error'); return; }

            const lines = history.map(h => `${h.role === 'assistant' ? 'AI' : 'Użytkownik'}: ${h.content}`);
            const blob = new Blob([lines.join('\n\n')], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'chat.txt';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        } catch (e) {
            Toasts.show(e.message || 'Błąd podczas pobierania rozmowy.', 'error');
        }
    }
};
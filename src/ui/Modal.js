import { Toasts } from './Toasts.js';

export const Modal = {
    callback: null,

    injectHTML: () => {
        // KILL ZOMBIE: Jeśli stary modal istnieje, usuń go, żeby wgrać nową wersję struktury
        const existing = document.getElementById('universal-modal');
        if (existing) existing.remove();

        const html = `
        <div id="universal-modal" class="hidden fixed inset-0 z-[9999] bg-black/90 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="glass-panel w-full max-w-lg p-0 rounded-xl border border-white/10 shadow-2xl overflow-hidden relative transform transition-all scale-100">
                
                <div class="p-5 border-b border-white/5 bg-white/5 flex justify-between items-center">
                    <h3 id="modal-title" class="font-bold text-white tracking-wide"></h3>
                    <button id="modal-close-x" class="text-gray-500 hover:text-white transition">✕</button>
                </div>

                <div class="p-6">
                    
                    <div id="modal-single-input-container" class="hidden">
                        <label id="modal-input-label" class="block text-xs text-blue-400 mb-2 uppercase font-bold tracking-wider"></label>
                        <input id="modal-input" type="text" class="w-full glass-input rounded-lg p-4 text-white outline-none focus:border-blue-500 transition">
                    </div>

                    <div id="modal-memory-form" class="hidden space-y-5">
                        <div>
                            <label class="block text-xs text-blue-400 mb-2 uppercase font-bold tracking-wider">Klucz (Temat)</label>
                            <input id="mem-key-input" type="text" class="w-full glass-input rounded-lg p-3 text-white outline-none focus:border-blue-500 transition font-mono text-sm" placeholder="np. STRATEGIA_CENOWA">
                        </div>
                        <div>
                            <label class="block text-xs text-blue-400 mb-2 uppercase font-bold tracking-wider">Treść Wiedzy</label>
                            <textarea id="mem-val-input" class="w-full glass-input rounded-lg p-3 text-gray-200 outline-none focus:border-blue-500 transition text-sm h-48 resize-none leading-relaxed" placeholder="Wpisz tutaj szczegóły..."></textarea>
                        </div>
                    </div>

                    <div id="modal-confirm-msg" class="hidden text-gray-300 text-center py-4 text-sm leading-relaxed"></div>

                </div>

                <div class="p-5 border-t border-white/5 bg-black/20 flex justify-end gap-3">
                    <button id="modal-cancel-btn" class="px-5 py-2.5 text-xs font-bold text-gray-500 hover:text-white uppercase tracking-wider transition">Anuluj</button>
                    <button id="modal-confirm-btn" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold rounded-lg shadow-lg shadow-blue-900/20 uppercase tracking-wider transition">Zapisz</button>
                </div>

            </div>
        </div>`;
        
        document.body.insertAdjacentHTML('beforeend', html);
        
        // Bind Close Events
        const close = () => Modal.close(false);
        document.getElementById('modal-close-x').onclick = close;
        document.getElementById('modal-cancel-btn').onclick = close;
    },

    open: (title, type = 'confirm', arg1 = '', arg2 = '') => {
        Modal.injectHTML(); // To teraz zawsze odświeży HTML
        
        const m = document.getElementById('universal-modal');
        const btn = document.getElementById('modal-confirm-btn');
        
        // Kontenery
        const singleCont = document.getElementById('modal-single-input-container');
        const memForm = document.getElementById('modal-memory-form');
        const confirmMsg = document.getElementById('modal-confirm-msg');
        
        // RESET (Ukryj wszystko)
        singleCont.classList.add('hidden');
        memForm.classList.add('hidden');
        confirmMsg.classList.add('hidden');
        
        // Tytuł
        document.getElementById('modal-title').innerText = title;
        m.classList.remove('hidden');

        // --- KONFIGURACJA PRZYCISKU (Reset stylów) ---
        // Domyślny styl (Niebieski - Zapisz)
        let btnClass = "px-6 py-2.5 bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold rounded-lg shadow-lg shadow-blue-900/20 uppercase tracking-wider transition";
        let btnText = "Zapisz";

        // === TRYB 1: PAMIĘĆ ===
        if (type === 'memory') {
            memForm.classList.remove('hidden');
            const kIn = document.getElementById('mem-key-input');
            const vIn = document.getElementById('mem-val-input');
            
            kIn.value = arg1; 
            vIn.value = arg2;
            
            setTimeout(() => { arg1 ? vIn.focus() : kIn.focus(); }, 50);

            return new Promise(resolve => {
                Modal.callback = resolve;
                btn.className = btnClass;
                btn.innerText = "Zapisz Wiedzę";
                btn.onclick = () => {
                    const k = kIn.value.trim();
                    const v = vIn.value.trim();
                    if(!k || !v) return Toasts.show('Wypełnij oba pola', 'error');
                    Modal.close({ key: k, value: v, originalKey: arg1 });
                };
            });
        }

        // === TRYB 2: INPUT ===
        else if (type === 'input') {
            singleCont.classList.remove('hidden');
            document.getElementById('modal-input-label').innerText = arg1; 
            const inp = document.getElementById('modal-input');
            inp.value = arg2; 
            setTimeout(() => inp.focus(), 50);
            
            inp.onkeydown = (e) => { if(e.key === 'Enter') btn.click(); };

            return new Promise(resolve => {
                Modal.callback = resolve;
                btn.className = btnClass;
                btn.innerText = "Zapisz";
                btn.onclick = () => {
                    if(!inp.value.trim()) return Toasts.show('Pole wymagane', 'error');
                    Modal.close(inp.value.trim());
                };
            });
        }

        // === TRYB 3: CONFIRM (USUWANIE) ===
        else {
            confirmMsg.classList.remove('hidden');
            confirmMsg.innerText = "Tej operacji nie można cofnąć."; 
            
            // Zmieniamy styl na Czerwony (Usuń)
            btnClass = "px-6 py-2.5 bg-red-600 hover:bg-red-500 text-white text-xs font-bold rounded-lg shadow-lg shadow-red-900/20 uppercase tracking-wider transition";
            
            return new Promise(resolve => {
                Modal.callback = resolve;
                btn.className = btnClass;
                btn.innerText = "Usuń";
                btn.onclick = () => Modal.close(true);
            });
        }
    },

    close: (result) => {
        const m = document.getElementById('universal-modal');
        if(m) m.classList.add('hidden');
        if(Modal.callback) Modal.callback(result);
        Modal.callback = null;
    }
};
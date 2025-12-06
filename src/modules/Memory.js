import { CONFIG } from '../config.js';
import { Store } from '../store.js';
import { Toasts } from '../ui/Toasts.js';
import { Modal } from '../ui/Modal.js';

export const Memory = {
    togglePanel: () => {
        const p = document.getElementById('memory-panel');
        if (p) p.classList.toggle('translate-x-full');
    },

    load: async () => {
        const list = document.getElementById('memory-list');
        const projectId = Store.get('currentProject');

        if (!projectId || !list) return;

        list.innerHTML = '<div class="space-y-2 animate-pulse opacity-50"><div class="h-8 bg-white/5 rounded"></div><div class="h-8 bg-white/5 rounded"></div></div>';

        try {
            const res = await fetch(`${CONFIG.API_URL}?action=get_memory&project_id=${projectId}`, { 
                headers: {'Authorization': Store.get('token')} 
            });

            const rawText = await res.text();
            let d;
            try { d = JSON.parse(rawText.trim()); } catch (e) { throw new Error("Błąd danych serwera"); }

            if (d.error) throw new Error(d.error);

            // --- OBSŁUGA PASKA POSTĘPU ---
            const usage = d.usage || 0;
            const data = d.data || {};
            
            // Aktualizacja %
            const percentEl = document.getElementById('mem-percent');
            if (percentEl) percentEl.innerText = usage + '%';
            
            // Aktualizacja paska (Bar)
            const bar = document.getElementById('mem-bar');
            if (bar) {
                bar.style.width = usage + '%';
                // Zmieniamy kolor na czerwony, gdy > 90%
                let colorClass = usage > 90 ? 'bg-red-500' : 'bg-blue-600';
                // Dodajemy efekt świecenia (shadow)
                bar.className = `h-full transition-all duration-500 ${colorClass} shadow-[0_0_10px_rgba(59,130,246,0.5)]`;
            }
            // -----------------------------

            list.innerHTML = '';
            const entries = Object.entries(data);
            
            if (entries.length === 0) {
                list.innerHTML = '<div class="text-center text-gray-500 text-xs mt-10">Pamięć jest pusta.<br>Dodaj kontekst, aby AI wiedziało więcej.</div>';
                return;
            }

            for (const [k, v] of entries) {
                const el = document.createElement('div');
                el.className = 'glass-panel p-3 rounded-lg border border-white/5 text-xs group relative hover:border-blue-500/30 transition mb-2';
                
                const safeKey = k.replace(/</g, "&lt;").replace(/>/g, "&gt;");
                const safeVal = v.replace(/</g, "&lt;").replace(/>/g, "&gt;");

                el.innerHTML = `
                    <div class="flex justify-between items-start mb-1">
                        <strong class="text-blue-400 font-mono tracking-tight text-[11px] uppercase">${safeKey}</strong>
                        <div class="flex gap-2 opacity-0 group-hover:opacity-100 transition">
                             <button class="edit-mem text-gray-400 hover:text-white transition" title="Edytuj">✎</button>
                             <button class="del-mem text-gray-400 hover:text-red-500 transition" title="Usuń">🗑</button>
                        </div>
                    </div>
                    <div class="text-gray-300 leading-relaxed break-words whitespace-pre-wrap opacity-90">${safeVal}</div>
                `;
                
                el.querySelector('.edit-mem').onclick = () => Memory.edit(k, v);
                el.querySelector('.del-mem').onclick = () => Memory.delete(k);
                
                list.appendChild(el);
            }

        } catch (e) {
            console.error(e);
            list.innerHTML = `<div class="p-2 text-red-400 text-xs border border-red-500/20 rounded bg-red-900/10">Błąd: ${e.message}</div>`;
        }
    },

    // NOWA FUNKCJA ADD (Jeden krok)
    add: async () => {
        // Wywołujemy modal z typem 'memory'. Zwraca obiekt {key, value} lub null
        const result = await Modal.open('Dodaj Kontekst Pamięci', 'memory');
        if (result) {
            Memory.save(result.key, result.value);
        }
    },

    // NOWA FUNKCJA EDIT (Jeden krok, prefill)
    edit: async (k, v) => {
        // Przekazujemy k i v jako wartości domyślne
        const result = await Modal.open(`Edycja: ${k}`, 'memory', k, v);
        if (result) {
            const original = result.originalKey || k;
            const act = result.key !== original ? 'rename' : 'update';
            Memory.save(result.key, result.value, act, original);
        }
    },

    delete: async (k) => {
        if (await Modal.open(`Usunąć wiedzę: ${k}?`, 'confirm')) {
            await Memory.save(k, '', 'delete');
        }
    },

    save: async (k, v, act = 'update', originalKey = '') => {
        try {
            const payload = {
                project_id: Store.get('currentProject'),
                key: act === 'rename' ? (originalKey || k) : k,
                value: v,
                act: act
            };

            if (act === 'rename') payload.new_key = k;

            await fetch(`${CONFIG.API_URL}?action=update_memory`, {
                method: 'POST',
                headers: {'Authorization': Store.get('token')},
                body: JSON.stringify(payload)
            });
            Memory.load();
            Toasts.show(act === 'delete' ? 'Usunięto' : 'Zapisano wiedzę');
        } catch(e) {
            Toasts.show('Błąd zapisu', 'error');
        }
    }
};
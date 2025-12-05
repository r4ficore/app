import { CONFIG } from '../config.js';
import { Store } from '../store.js';
import { Toasts } from '../ui/Toasts.js';
import { Modal } from '../ui/Modal.js';
// Importujemy dynamicznie w metodach lub używamy globalnego obiektu BrandOS, 
// aby uniknąć problemów z cyklicznymi zależnościami (Circular Dependency) w fazie 1.

export const Project = {
    loadAll: async () => {
        try {
            const res = await fetch(`${CONFIG.API_URL}?action=get_projects`, { 
                headers: {'Authorization': Store.get('token')} 
            });
            if (res.status === 401) return location.reload(); // Quick logout
            
            const data = await res.json();
            Store.set('projects', data.projects);
            
            const sel = document.getElementById('project-select');
            sel.innerHTML = '';
            
            data.projects.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id; opt.text = p.name;
                sel.appendChild(opt);
            });

            if (data.projects.length > 0) {
                // Jeśli nie ma wybranego projektu, wybierz pierwszy
                if (!Store.get('currentProject')) {
                    sel.value = data.projects[0].id;
                    Project.change();
                }
            }
        } catch (e) {
            Toasts.show('Błąd ładowania projektów', 'error');
        }
    },

    create: async () => {
        const name = await Modal.open('Nowy Projekt', 'input', 'Nazwa projektu');
        if (!name) return;

        try {
            const res = await fetch(`${CONFIG.API_URL}?action=create_project`, {
                method: 'POST', 
                headers: {'Authorization': Store.get('token')}, 
                body: JSON.stringify({name})
            });
            const d = await res.json();
            
            if (d.error) throw new Error(d.error);
            
            await Project.loadAll();
            document.getElementById('project-select').value = d.id;
            Project.change();
            Toasts.show('Projekt utworzony');
        } catch (e) {
            Toasts.show(e.message, 'error');
        }
    },

    change: () => {
        const pid = document.getElementById('project-select').value;
        Store.set('currentProject', pid);
        
        // Wywołujemy inne moduły (zakładając, że App.js je załadował do window.BrandOS)
        if (window.BrandOS) {
            window.BrandOS.Chat.loadSessions();
            window.BrandOS.Chat.startNew();
            window.BrandOS.Memory.load();
        }
    }
};
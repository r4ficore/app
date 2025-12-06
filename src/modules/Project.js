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

            sel.onchange = Project.change;

            if (data.projects.length > 0) {
                // Jeśli nie ma wybranego projektu, wybierz pierwszy
                const hasCurrent = data.projects.some(p => p.id === Store.get('currentProject'));
                if (!Store.get('currentProject') || !hasCurrent) {
                    Store.set('currentProject', data.projects[0].id);
                }
                sel.value = Store.get('currentProject');
                Project.change();
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

    rename: async () => {
        const currentId = Store.get('currentProject');
        const projects = Store.get('projects') || [];
        const current = projects.find(p => p.id === currentId);
        if (!current) return Toasts.show('Brak projektu do zmiany', 'error');

        const newName = await Modal.open('Zmień nazwę projektu', 'input', 'Nowa nazwa', current.name);
        if (!newName) return;

        try {
            const res = await fetch(`${CONFIG.API_URL}?action=rename_project`, {
                method: 'POST',
                headers: {'Authorization': Store.get('token')},
                body: JSON.stringify({ id: currentId, name: newName })
            });
            const d = await res.json();
            if (d.error) throw new Error(d.error);
            await Project.loadAll();
            document.getElementById('project-select').value = currentId;
            Toasts.show('Nazwa projektu zaktualizowana');
        } catch (e) {
            Toasts.show(e.message || 'Błąd zmiany nazwy', 'error');
        }
    },

    remove: async () => {
        const currentId = Store.get('currentProject');
        if (!currentId) return Toasts.show('Brak projektu do usunięcia', 'error');
        if (!(await Modal.open('Usunąć projekt?', 'confirm'))) return;

        try {
            const res = await fetch(`${CONFIG.API_URL}?action=delete_project`, {
                method: 'POST',
                headers: {'Authorization': Store.get('token')},
                body: JSON.stringify({ id: currentId })
            });
            const d = await res.json();
            if (d.error) throw new Error(d.error);

            await Project.loadAll();
            const sel = document.getElementById('project-select');
            if (sel && sel.value) {
                Store.set('currentProject', sel.value);
                Project.change();
            }

            Toasts.show('Projekt usunięty');
        } catch (e) {
            Toasts.show(e.message || 'Błąd usuwania', 'error');
        }
    },

    change: () => {
        const sel = document.getElementById('project-select');
        const pid = sel ? sel.value : null;
        Store.set('currentProject', pid);

        // Wywołujemy inne moduły (zakładając, że App.js je załadował do window.BrandOS)
        if (window.BrandOS) {
            window.BrandOS.Chat.loadSessions();
            window.BrandOS.Chat.startNew();
            window.BrandOS.Memory.load();
        }
    }
};
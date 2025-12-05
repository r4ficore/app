import { Auth } from '../modules/Auth.js';
import { Toasts } from '../ui/Toasts.js';

class Application {
    constructor() {
        this.init();
    }

    init() {
        console.log('🚀 Brand OS v2.2 (Modular) Initializing...');
        
        // Global Error Handler
        window.addEventListener('offline', () => Toasts.show('Jesteś offline', 'error'));
        window.addEventListener('online', () => Toasts.show('Połączenie przywrócone'));

        // Event Listeners dla logowania
        this.bindAuthEvents();

        // Check Auth
        Auth.init(() => {
            this.loadModules();
        });
    }

    bindAuthEvents() {
        const loginForm = document.querySelector('form');
        if(loginForm) {
            loginForm.onsubmit = (e) => {
                e.preventDefault();
                const u = document.getElementById('login-user').value.trim();
                const p = document.getElementById('login-pass').value.trim();
                if(u && p) Auth.login(u, p, () => this.loadModules());
            };
        }
    }

    async loadModules() {
        // CACHE BUSTING: Naprawiona nazwa zmiennej (v, a nie 1)
        const v = Date.now() + '_fix_zombie_dom';
        
        try {
            console.log('Ładowanie modułów z wymuszeniem odświeżenia...');
            
            // Dynamiczne importy z parametrem ?v=... aby ominąć cache
            // Zauważ: Modal jest w folderze ui, reszta w modules
            const { Project } = await import(`../modules/Project.js?v=${v}`);
            const { Chat }    = await import(`../modules/Chat.js?v=${v}`);
            const { Memory }  = await import(`../modules/Memory.js?v=${v}`);
            const { Modal }   = await import(`../ui/Modal.js?v=${v}`);
            
            // Przypisanie do window (Bridge dla HTML onclick)
            window.BrandOS = {
                Auth,
                Project,
                Chat,
                Memory,
                Modal, 
                UI: { Toasts }
            };

            // Inicjalizacja komponentów
            Modal.injectHTML(); // Wstrzykujemy HTML modala do strony
            await Project.loadAll(); // Ładujemy projekty
            
            console.log('✅ Moduły załadowane (Fresh)');
        } catch (e) {
            console.error('Failed to load modules', e);
            Toasts.show('Błąd krytyczny aplikacji: Sprawdź konsolę', 'error');
        }
    }
}

// Start
new Application();
import { CONFIG } from '../config.js';
import { Store } from '../store.js';
import { Toasts } from '../ui/Toasts.js';

export const Auth = {
    init: (onSuccessCallback) => {
        if (Store.get('token')) {
            document.getElementById('auth-screen').classList.add('hidden');
            document.getElementById('app-screen').classList.remove('hidden');
            if(onSuccessCallback) onSuccessCallback();
        } else {
            document.getElementById('auth-screen').classList.remove('hidden');
            document.getElementById('app-screen').classList.add('hidden');
        }
    },

    login: async (username, password, onSuccessCallback) => {
        try {
            const res = await fetch(`${CONFIG.API_URL}?action=login`, {
                method: 'POST',
                body: JSON.stringify({username, password})
            });
            if (!res.ok) throw new Error('Błędne dane logowania');
            
            const data = await res.json();
            localStorage.setItem('token', data.token);
            Store.set('token', data.token);
            Store.set('user', data.username);
            
            Auth.init(onSuccessCallback);
            Toasts.show(`Witaj ponownie, ${data.username}`);
        } catch (e) {
            Toasts.show(e.message, 'error');
        }
    },

    register: async () => {
        const username = document.getElementById('login-user')?.value.trim();
        const password = document.getElementById('login-pass')?.value.trim();

        if (!username || !password) {
            Toasts.show('Podaj login i hasło, aby założyć konto.', 'error');
            return;
        }

        try {
            const res = await fetch(`${CONFIG.API_URL}?action=register`, {
                method: 'POST',
                body: JSON.stringify({ username, password })
            });

            if (!res.ok) {
                const err = await res.json().catch(() => ({}));
                throw new Error(err.error || 'Rejestracja nie powiodła się');
            }

            Toasts.show('Konto utworzone. Logowanie...');
            await Auth.login(username, password, () => window.location.reload());
        } catch (e) {
            Toasts.show(e.message, 'error');
        }
    },

    logout: () => {
        localStorage.removeItem('token');
        location.reload();
    }
};
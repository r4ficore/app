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

    logout: () => {
        localStorage.removeItem('token');
        location.reload();
    }
};
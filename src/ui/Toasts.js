import { CONFIG } from '../config.js';

export const Toasts = {
    show: (msg, type = 'info') => {
        const container = document.getElementById('toast-container');
        const el = document.createElement('div');
        
        const colors = type === 'error' 
            ? 'bg-red-900/90 border-red-500/30 text-red-100' 
            : 'bg-blue-900/90 border-blue-500/30 text-blue-100';

        el.className = `flex items-center gap-3 px-4 py-3 rounded border backdrop-blur shadow-xl transform transition-all duration-300 translate-x-10 opacity-0 ${colors} text-xs font-medium z-50`;
        el.innerHTML = `<span>${type==='error'?'⚠️':'ℹ️'}</span> ${msg}`;
        
        container.appendChild(el);
        
        requestAnimationFrame(() => el.classList.remove('translate-x-10', 'opacity-0'));
        
        setTimeout(() => {
            el.classList.add('translate-x-10', 'opacity-0');
            setTimeout(() => el.remove(), 300);
        }, CONFIG.TOAST_DURATION);
    }
};
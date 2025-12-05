/public_html
│
├── index.html            # Tylko szkielet HTML + import modułów
├── api.php               # Backend (bez zmian na razie)
│
├── /assets
│   ├── /css
│   │   └── style.css     # Custom style (glassmorphism) i Tailwind directives
│   └── /vendor
│       ├── marked.min.js
│       └── dompurify.min.js  <-- NOWOŚĆ (Security)
│
└── /src
    ├── config.js         # Stałe (API endpoints, Limity)
    ├── store.js          # Stan globalny (State Management)
    ├── utils.js          # Helpery (Debounce, Formatowanie dat)
    │
    ├── /core
    │   ├── App.js        # Główny kontroler (Init)
    │   ├── Router.js     # (Opcjonalnie) jeśli dodamy podstrony
    │   └── EventBus.js   # Komunikacja między modułami
    │
    ├── /modules
    │   ├── Auth.js       # Logowanie/Rejestracja
    │   ├── Chat.js       # Logika czatu + WebSocket placeholder
    │   ├── Project.js    # Zarządzanie projektami
    │   └── Memory.js     # Panel pamięci
    │
    └── /ui
        ├── Render.js     # Bezpieczne renderowanie (DOMPurify)
        ├── Modal.js      # System modali
        ├── Toasts.js     # Powiadomienia
        └── Theme.js      # Dark/Light mode logic

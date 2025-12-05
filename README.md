# app

## Struktura

- `index.html` – główny frontend aplikacji Brand OS (czat, panel pamięci, przełącznik trybu online/offline).
- `api.php` – backend (PHP) obsługujący logowanie, projekty, pamięć, sesje, czat oraz integracje (DeepSeek, Tavily, health).
- `src/` – moduły frontendu (Auth, Chat, Memory, Project), UI (Render, Modal, Toasts) i konfiguracja.
- `data/` – pliki danych użytkowników (chronione przed bezpośrednim dostępem przez `.htaccess`).
- `archiwum/` – zarchiwizowane, nieużywane warianty frontu/backu (index2.html, stary api.php). **Nie są ładowane w środowisku produkcyjnym**; traktuj je jako referencję historyczną.

## Notatki wdrożeniowe

- Bieżący kod produkcyjny to pliki w katalogu głównym (`index.html`, `api.php`, `src/`).
- Folder `archiwum/` pozostaje jedynie jako dokumentacja zmian; nie powinien być serwowany ani mylony z aktualną wersją. W przypadku wdrożeń zaleca się wyłączenie jego ekspozycji lub usunięcie przed publikacją.
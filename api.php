<?php
// --- KONFIGURACJA GŁÓWNA ---
set_time_limit(900);
ini_set('max_execution_time', 900);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
ini_set('implicit_flush', 1);
error_reporting(0);
ini_set('display_errors', 0);

$env = function(string $key, $default = '') {
    $val = getenv($key);
    return ($val !== false && $val !== '') ? $val : $default;
};

$DEEPSEEK_KEY = $env('DEEPSEEK_KEY');
$TAVILY_KEY = $env('TAVILY_KEY');
$DATA_DIR = rtrim($env('DATA_DIR', __DIR__ . '/data'), '/');
$MEMORY_LIMIT = 20000;
$PROJECT_LIMIT = 2;
$SESSION_TTL_DAYS = intval($env('SESSION_TTL_DAYS', 30));
$MAX_UPLOAD_SIZE = 2 * 1024 * 1024; // 2MB
$ALLOWED_UPLOAD_MIME = ['text/plain', 'text/markdown', 'text/x-markdown', 'application/json', 'text/csv'];
$MAX_FILE_PROMPT_CHARS = 12000;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
if (!file_exists($DATA_DIR)) { mkdir($DATA_DIR, 0755, true); }
$logDir = $DATA_DIR . '/logs';
if (!file_exists($logDir)) { mkdir($logDir, 0755, true); }

$missing_keys = [];
if (empty($DEEPSEEK_KEY)) { $missing_keys[] = 'DEEPSEEK_KEY'; }
if (empty($TAVILY_KEY)) { $missing_keys[] = 'TAVILY_KEY'; }
if (!empty($missing_keys)) {
    http_response_code(500);
    send_json(['error' => 'Brak wymaganych kluczy API: ' . implode(', ', $missing_keys)]);
}

$missing_keys = [];
if (empty($DEEPSEEK_KEY)) { $missing_keys[] = 'DEEPSEEK_KEY'; }
if (empty($TAVILY_KEY)) { $missing_keys[] = 'TAVILY_KEY'; }
if (!empty($missing_keys)) {
    http_response_code(500);
    send_json(['error' => 'Brak wymaganych kluczy API: ' . implode(', ', $missing_keys)]);
}

// --- HELPERY ---
function log_error(string $message): void {
    global $logDir;
    $line = '[' . date('Y-m-d H:i:s') . "] " . $message . "\n";
    @file_put_contents($logDir . '/errors.log', $line, FILE_APPEND);
}
function get_json($filename) {
    global $DATA_DIR;
    $path = $DATA_DIR . '/' . basename($filename);
    if (file_exists($path)) {
        $content = file_get_contents($path);
        return json_decode($content, true) ?: [];
    }
    return [];
}

function save_json($filename, $data) {
    global $DATA_DIR;
    file_put_contents($DATA_DIR . '/' . basename($filename), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function sanitize_file_content(string $content, int $maxLen) {
    // Usuń znaki kontrolne (poza nową linią/tab) i przytnij do bezpiecznej długości
    $content = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $content);
    $content = mb_substr($content, 0, $maxLen);
    return $content;
}

function send_json($data) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function send_error($msg, $code = 400) {
    http_response_code($code);
    send_json(['error' => $msg]);
}

// 1. Tavily (Szukanie ogólne)
function search_tavily($query, $api_key) {
    if (empty($api_key)) {
        return ['error' => 'Brak klucza Tavily API'];
    }

    $ch = curl_init('https://api.tavily.com/search');
    $data = json_encode([
        'api_key' => $api_key,
        'query' => $query,
        'search_depth' => 'advanced',
        'include_answer' => true,
        'max_results' => 5
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        log_error('Tavily curl_error: ' . $err);
        curl_close($ch);
        return ['error' => 'Błąd połączenia z Tavily'];
    }

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        log_error("Tavily HTTP {$http_code} dla zapytania: {$query}");
        return ['error' => "API Error: $http_code"];
    }

    $decoded = json_decode($response, true);
    if ($decoded === null) {
        log_error('Tavily JSON decode failed dla zapytania: ' . $query);
        return ['error' => 'Niepoprawna odpowiedź Tavily'];
    }

    return $decoded;
}

function build_tavily_query(string $message): string {
    $trimmed = trim($message);

    if (preg_match('/\bhttps?:\/\/[^\s]+/i', $trimmed, $matches)) {
        $url = $matches[0];
        if ($trimmed !== $url) {
            return $trimmed;
        }

        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        return "Najważniejsze informacje i aktualności ze strony {$host} ({$url})";
    }

    return $trimmed;
}

// 2. Simple Scraper (Wchodzenie w linki bezpośrednio)
function simple_scrape($url, int $retries = 2) {
    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        // Udajemy przeglądarkę, żeby nas nie zablokowali
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);

        $html = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || empty($html)) {
            log_error("Scrape attempt {$attempt} failed for {$url}: {$error}");
            if ($attempt === $retries) return false;
            usleep(200000);
            continue;
        }

        // Czyścimy HTML do samego tekstu
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', "", $html);
        $text = strip_tags($html);
        $text = preg_replace('/\s+/', ' ', $text); // Usuń nadmiar spacji

        return mb_substr(trim($text), 0, 15000); // Limit znaków dla modelu
    }

    return false;
}

function prune_old_sessions(string $user, string $projectId) {
    global $SESSION_TTL_DAYS, $DATA_DIR;

    if ($SESSION_TTL_DAYS <= 0) return;

    $file = "sessions_list_{$user}_{$projectId}.json";
    $list = get_json($file);
    if (empty($list)) return;

    $threshold = (new DateTimeImmutable())->modify("-{$SESSION_TTL_DAYS} days");
    $updated_list = [];

    foreach ($list as $session) {
        $updated = $session['updated_at'] ?? $session['created_at'] ?? null;
        $updated_dt = $updated ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $updated) : false;

        if ($updated_dt && $updated_dt < $threshold) {
            @unlink($DATA_DIR . '/' . basename("chat_{$user}_{$session['id']}.json"));
            continue;
        }

        $updated_list[] = $session;
    }

    if ($updated_list !== $list) {
        save_json($file, $updated_list);
    }
}

// --- LOGIKA ENDPOINTÓW ---
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

// Health check (public, bez wrażliwych danych)
if ($action === 'health') {
    $health = [
        'status' => 'ok',
        'time' => date('c'),
        'data_dir' => [
            'exists' => is_dir($DATA_DIR),
            'writable' => is_writable($DATA_DIR)
        ],
        'dependencies' => [
            'curl' => function_exists('curl_version'),
            'json' => function_exists('json_encode')
        ],
        'api_keys' => [
            'deepseek_configured' => !empty($DEEPSEEK_KEY),
            'tavily_configured' => !empty($TAVILY_KEY)
        ]
    ];

    send_json($health);
}

// Auth & Users
if ($action === 'register') {
    $users = get_json('users.json');
    $u = strtolower(trim($input['username'] ?? ''));
    if (!$u || !$input['password']) send_error('Brak danych');
    if (isset($users[$u])) send_error('Login zajęty');
    $users[$u] = password_hash($input['password'], PASSWORD_DEFAULT);
    save_json('users.json', $users);
    $def_proj_id = uniqid('p_');
    save_json("projects_{$u}.json", [['id' => $def_proj_id, 'name' => 'Start']]);
    send_json(['message' => 'OK']);
}

if ($action === 'login') {
    $users = get_json('users.json');
    $u = strtolower(trim($input['username'] ?? ''));
    if (isset($users[$u]) && password_verify($input['password'] ?? '', $users[$u])) {
        $token = bin2hex(random_bytes(16));
        $sessions = get_json('sessions_auth.json');
        $sessions[$token] = $u;
        save_json('sessions_auth.json', $sessions);
        send_json(['token' => $token, 'username' => $u]);
    } else {
        send_error('Błędne dane', 401);
    }
}

// Middleware
$headers = getallheaders();
$token = $headers['Authorization'] ?? ($_POST['token'] ?? '');
$sessions = get_json('sessions_auth.json');

if ($action !== 'chat' && !isset($sessions[$token])) send_error('Auth fail', 401);

if ($action === 'chat' && isset($_POST['token'])) {
    if(!isset($sessions[$_POST['token']])) send_error('Auth fail', 401);
    $current_user = $sessions[$_POST['token']];
} else {
    $current_user = $sessions[$token] ?? '';
}

// Projekty & Pamięć
if ($action === 'get_projects') {
    $projs = get_json("projects_{$current_user}.json");
    if (empty($projs)) {
        $projs = [['id' => uniqid('p_'), 'name' => 'Start']];
        save_json("projects_{$current_user}.json", $projs);
    }
    send_json(['projects' => $projs]);
}

if ($action === 'create_project') {
    $projs = get_json("projects_{$current_user}.json");
    if (count($projs) >= $PROJECT_LIMIT) send_error("Limit max $PROJECT_LIMIT projekty.");
    $new_id = uniqid('p_');
    $name = trim($input['name'] ?? 'Nowy');
    $projs[] = ['id' => $new_id, 'name' => $name ?: 'Nowy'];
    save_json("projects_{$current_user}.json", $projs);
    send_json(['id' => $new_id, 'name' => $name]);
}

if ($action === 'rename_project') {
    $projectId = $input['id'] ?? '';
    $newName = trim($input['name'] ?? '');
    if (!$projectId || !$newName) send_error('Brak danych projektu');

    $projs = get_json("projects_{$current_user}.json");
    $updated = false;
    foreach ($projs as &$p) {
        if ($p['id'] === $projectId) { $p['name'] = $newName; $updated = true; break; }
    }

    if (!$updated) send_error('Projekt nie istnieje', 404);
    save_json("projects_{$current_user}.json", $projs);
    send_json(['status' => 'ok', 'name' => $newName]);
}

if ($action === 'delete_project') {
    $projectId = $input['id'] ?? '';
    if (!$projectId) send_error('Brak ID projektu');

    $projs = get_json("projects_{$current_user}.json");
    if (count($projs) <= 1) send_error('Nie można usunąć ostatniego projektu', 400);

    $projs = array_values(array_filter($projs, fn($p) => $p['id'] !== $projectId));
    save_json("projects_{$current_user}.json", $projs);

    // Usuwamy pamięć i historię sesji dla projektu
    @unlink($DATA_DIR . '/' . basename("mem_{$current_user}_{$projectId}.json"));
    $sessionsFile = "sessions_list_{$current_user}_{$projectId}.json";
    $sessions = get_json($sessionsFile);
    foreach ($sessions as $s) {
        @unlink($DATA_DIR . '/' . basename("chat_{$current_user}_{$s['id']}.json"));
    }
    @unlink($DATA_DIR . '/' . basename($sessionsFile));

    send_json(['status' => 'ok', 'projects' => $projs]);
}

if ($action === 'get_memory') {
    $pid = $_GET['project_id'] ?? 'default';
    $mem = get_json("mem_{$current_user}_{$pid}.json");
    $percent = $MEMORY_LIMIT > 0 ? min(100, intval((strlen(json_encode($mem)) / $MEMORY_LIMIT) * 100)) : 0;
    send_json(['data' => $mem, 'usage' => $percent, 'limit' => $MEMORY_LIMIT]);
}

if ($action === 'update_memory') {
    $pid = $input['project_id'];
    $key = $input['key'];
    $val = $input['value'] ?? '';
    $act = $input['act'] ?? 'update';
    $file = "mem_{$current_user}_{$pid}.json";
    $mem = get_json($file);

    if ($act === 'delete') {
        if (isset($mem[$key])) unset($mem[$key]);
    } elseif ($act === 'rename') {
        $newKey = $input['new_key'] ?? '';
        if (!$newKey) send_error('Brak nowego klucza pamięci');
        $mem[$newKey] = $val ?: ($mem[$key] ?? '');
        if ($newKey !== $key && isset($mem[$key])) unset($mem[$key]);
    } else {
        $mem[$key] = $val;
    }

    save_json($file, $mem);
    send_json(['status' => 'ok']);
}

if ($action === 'get_sessions') {
    $pid = $_GET['project_id'] ?? '';
    prune_old_sessions($current_user, $pid);
    $sessions = get_json("sessions_list_{$current_user}_{$pid}.json");
    usort($sessions, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
    send_json(['sessions' => $sessions]);
}

if ($action === 'get_chat_history') {
    $sid = $_GET['session_id'] ?? '';
    send_json(['history' => get_json("chat_{$current_user}_{$sid}.json")]);
}

if ($action === 'delete_session') {
    $sid = $input['session_id'];
    $pid = $input['project_id'];
    $list = get_json("sessions_list_{$current_user}_{$pid}.json");
    $list = array_values(array_filter($list, fn($s) => $s['id'] !== $sid));
    save_json("sessions_list_{$current_user}_{$pid}.json", $list);
    @unlink($DATA_DIR . '/' . basename("chat_{$current_user}_{$sid}.json"));
    send_json(['status' => 'ok']);
}

// --- GŁÓWNA LOGIKA CHATU ---
if ($action === 'chat') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/x-ndjson');
    header('X-Accel-Buffering: no');
    
    $message = $_POST['message'] ?? '';
    $project_id = $_POST['project_id'] ?? 'default';
    $session_id = $_POST['session_id'] ?? uniqid('s_');
    prune_old_sessions($current_user, $project_id);
    $is_new_session = !file_exists($DATA_DIR . "/chat_{$current_user}_{$session_id}.json");

    echo json_encode(['status' => 'ping']) . "\n"; flush();
    if ($is_new_session) { echo json_encode(['status' => 'session_init', 'id' => $session_id]) . "\n"; flush(); }

    // Plik
    $file_content = "";
    if (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            log_error('Upload failed: ' . $_FILES['file']['error']);
            echo json_encode(['status' => 'file_error', 'msg' => 'Błąd przesyłania pliku.']) . "\n"; flush(); exit;
        }
        if ($_FILES['file']['size'] > $MAX_UPLOAD_SIZE) {
            log_error('Upload rejected (size) dla użytkownika ' . $current_user);
            echo json_encode(['status' => 'file_error', 'msg' => 'Plik przekracza 2MB.']) . "\n"; flush(); exit;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $_FILES['file']['tmp_name']) : '';
        if ($finfo) finfo_close($finfo);

        if ($mime && !in_array($mime, $ALLOWED_UPLOAD_MIME)) {
            log_error('Upload rejected (mime) dla użytkownika ' . $current_user . ' mime=' . $mime);
            echo json_encode(['status' => 'file_error', 'msg' => 'Niedozwolony typ pliku. Dozwolone: TXT/MD/JSON/CSV.']) . "\n"; flush(); exit;
        }

        $raw_content = file_get_contents($_FILES['file']['tmp_name']);
        $clean_content = sanitize_file_content($raw_content ?: '', $MAX_FILE_PROMPT_CHARS);
        $file_content = "\n\n--- ZAŁĄCZNIK: {$_FILES['file']['name']} ---\n" . $clean_content . "\n------\n";
    }

    // Pamięć
    $mem = get_json("mem_{$current_user}_{$project_id}.json");
    $memory_context_str = "MEMORY CONTEXT:\n";
    foreach ($mem as $k => $v) { $memory_context_str .= "- {$k}: {$v}\n"; }

    // --- DETEKCJA URL I SCRAPING ---
    $url_context = "";
    $internet_context = "";
    $scraped_success = false;
    
    // Szukamy URL w wiadomości
    $allowedSchemes = ['http', 'https'];
    $urls = [];
    if (preg_match_all('/\bhttps?:\/\/[^\s]+/i', $message, $matches)) {
        foreach (array_unique($matches[0]) as $candidate) {
            $scheme = strtolower(parse_url($candidate, PHP_URL_SCHEME) ?: '');
            if (in_array($scheme, $allowedSchemes)) { $urls[] = $candidate; }
            else { log_error('Odrzucono URL spoza whitelisty: ' . $candidate); }
        }
    }

    if (!empty($urls)) {
        $url_to_scrape = $urls[0];
        if (count($urls) > 1) {
            echo json_encode(['status' => 'searching', 'msg' => 'Wykryto wiele linków — analizuję pierwszy.']) . "\n"; flush();
            log_error('Wiele URL w wiadomości, użyto: ' . $url_to_scrape);
        }

        echo json_encode(['status' => 'searching', 'msg' => 'Pobieram treść strony...']) . "\n"; flush();

        $scraped_content = simple_scrape($url_to_scrape);
        if ($scraped_content) {
            $url_context = "\n\n=== TREŚĆ POBRANA ZE STRONY ($url_to_scrape) ===\n$scraped_content\n===============================\n";
            $scraped_success = true;
        } else {
            log_error('Scrape_error dla ' . $url_to_scrape);
            echo json_encode(['status' => 'scrape_error', 'msg' => 'Nie udało się pobrać treści strony.']) . "\n"; flush();
        }
    }

    // --- LOGIKA TAVILY (Jeśli nie scrapujemy lub user wymusił szukanie) ---
    $manual_search = ($_POST['use_search'] ?? '0') === '1';
    $internet_context = "";

    // Jeśli nie udało się pobrać strony (lub nie było URL), a user chce szukać:
    if (($manual_search || preg_match('/(cena|kto|gdzie|kiedy|news)/i', $message)) && !$scraped_success) {
        echo json_encode(['status' => 'searching', 'msg' => 'Szukam w sieci...']) . "\n"; flush();
        $tavily_query = build_tavily_query($message);
        $tavily_res = search_tavily($tavily_query, $TAVILY_KEY);
        if (!isset($tavily_res['error'])) {
            $internet_context = "\n\n=== WYNIKI WYSZUKIWANIA ===\n";
            if (!empty($tavily_res['results'])) {
                foreach ($tavily_res['results'] as $r) {
                    $internet_context .= "URL: {$r['url']}\nTITLE: {$r['title']}\nCONTENT: {$r['content']}\n---\n";
                }
            } else {
                $internet_context .= "Brak wyników. Nie używaj wiedzy treningowej do faktów czasowych.\n";
            }
        } else {
            log_error('Tavily search_error: ' . ($tavily_res['error'] ?? 'unknown'));
            echo json_encode(['status' => 'search_error', 'msg' => $tavily_res['error']]) . "\n"; flush();
        }
    }

    // Prompt
    $current_date = date('Y-m-d H:i');

    $system_prompt = <<<EOT
Jesteś BRAND OS (Wersja: Modular). Twoim operatorem jest Rafi.
Twoim celem jest: SKUTECZNOŚĆ, PRECYZJA i SENS.
Nie bawisz się w uprzejmości AI. Działasz jak analityczny partner biznesowy.

### HIERARCHIA DANYCH (ŹRÓDŁA PRAWDY):
1. [NAJWYŻSZY PRIORYTET] === TREŚĆ POBRANA ZE STRONY ===
   Jeśli w kontekście widzisz sekcję "TREŚĆ POBRANA ZE STRONY", traktuj to jako fakt absolutny dla bieżącego zadania. Ignoruj swoją wiedzę treningową, jeśli jest sprzeczna z tym tekstem.
   
2. [WYSOKI PRIORYTET] === WYNIKI WYSZUKIWANIA (ONLINE) ===
   Aktualne dane z sieci. Używaj ich do faktów, dat, cen i newsów. Zawsze cytuj źródło, jeśli podajesz fakt (np. [Domena.com]).

3. [ŚREDNI PRIORYTET] === MEMORY CONTEXT ===
   To długoterminowa pamięć projektu. Używaj jej, by zachować spójność z poprzednimi ustaleniami (styl marki, założenia biznesowe, tech stack).

4. [NISKI PRIORYTET] Twoja wiedza treningowa.
   Używaj tylko do ogólnej logiki, kodowania i kreatywności.

### INSTRUKCJE OPERACYJNE:
- **Język:** Polski (chyba że Rafi zapyta po angielsku).
- **Styl:** Zwięzły, techniczny, "żołnierski". Bez lania wody.
- **Kod:** Jeśli piszesz kod, ma być gotowy do wdrożenia (production-ready). Używaj bloków ```language.
- **Źródła online:** Jeśli dostępna jest sekcja "TREŚĆ POBRANA ZE STRONY" lub "WYNIKI WYSZUKIWANIA", opieraj się na nich w pierwszej kolejności i zawsze podawaj źródło w nawiasie kwadratowym (np. [example.com] lub [źródło: domena]).
- **Źródła online:** Jeśli dostępna jest sekcja "TREŚĆ POBRANA ZE STRONY" lub "WYNIKI WYSZUKIWANIA", opieraj się na nich w pierwszej kolejności i zawsze podawaj źródło w nawiasie kwadratowym (np. [example.com] lub [źródło: domena]). Jeśli sekcja "WYNIKI WYSZUKIWANIA" zawiera komunikat o braku wyników, powiedz to wprost i nie korzystaj z wiedzy treningowej do podawania faktów czasowych.
- **Brak wiedzy:** Jeśli czegoś nie ma w wynikach wyszukiwania ani na stronie, powiedz wprost: "Brak danych w źródłach". Nie halucynuj i nie dopowiadaj.
- **Sprzeczności:** W przypadku konfliktu między treningiem a danymi z sieci/strony, wybieraj dane z sieci/strony. W przypadku sprzeczności między różnymi wynikami online, wskaż to i zaznacz brak pewności.
- **Formatowanie:** Używaj pogrubień (**kluczowe wnioski**) i list wypunktowanych dla czytelności.

### AKTUALNA DATA:
Dziś jest: {$current_date}

{$memory_context_str}
EOT;

    $messages = [['role' => 'system', 'content' => $system_prompt]];
    
    // Historia
    $chat_history = get_json("chat_{$current_user}_{$session_id}.json");
    foreach (array_slice($chat_history, -6) as $h) { $messages[] = ['role' => $h['role'], 'content' => $h['content']]; }

    // Final message construct
    $final_user_msg = $url_context . $internet_context . "\nUSER QUESTION: " . $message . $file_content;
    $messages[] = ['role' => 'user', 'content' => $final_user_msg];

    // DeepSeek Call
    $ch = curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'deepseek-chat',
        'messages' => $messages,
        'stream' => true
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer $DEEPSEEK_KEY"]);

    $full_bot_response = "";
    $curl_error = "";
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$full_bot_response) {
        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, 'data: ') === 0) {
                $json_str = substr($line, 6);
                if ($json_str === '[DONE]') continue;
                $json = json_decode($json_str, true);
                if (isset($json['choices'][0]['delta']['content'])) {
                    $txt = $json['choices'][0]['delta']['content'];
                    $full_bot_response .= $txt;
                    echo json_encode(['status' => 'content', 'text' => $txt]) . "\n";
                    flush();
                }
            }
        }
        return strlen($data);
    });

    $execResult = curl_exec($ch);
    if ($execResult === false) {
        $curl_error = curl_error($ch);
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!empty($curl_error)) {
        log_error('DeepSeek curl_error: ' . $curl_error);
        echo json_encode(['status' => 'llm_error', 'msg' => 'Błąd połączenia z modelem: ' . $curl_error]) . "\n"; flush();
        exit;
    }

    if ($httpCode >= 400) {
        log_error("DeepSeek HTTP {$httpCode} for session {$session_id}");
        echo json_encode(['status' => 'llm_error', 'msg' => "Model zwrócił błąd HTTP {$httpCode}"]) . "\n"; flush();
        exit;
    }

    if (empty($full_bot_response)) {
        echo json_encode(['status' => 'llm_error', 'msg' => 'Model nie zwrócił żadnej treści.']) . "\n"; flush();
        exit;
    }

    $chat_history[] = ['role' => 'user', 'content' => $message . ($file_content ? " [Plik]" : "")];
    $chat_history[] = ['role' => 'assistant', 'content' => $full_bot_response];
    save_json("chat_{$current_user}_{$session_id}.json", $chat_history);

    // Update listy
    $list_file = "sessions_list_{$current_user}_{$project_id}.json";
    $sessions_list = get_json($list_file);
    $exists = false;
    foreach ($sessions_list as &$s) {
        if ($s['id'] === $session_id) { $s['updated_at'] = date('Y-m-d H:i:s'); $s['title'] = mb_substr($message, 0, 30).'...'; $exists = true; break; }
    }
    if (!$exists) {
        $sessions_list[] = ['id' => $session_id, 'title' => mb_substr($message, 0, 30).'...', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
    }
    save_json($list_file, $sessions_list);

    echo json_encode(['status' => 'done', 'session_id' => $session_id]) . "\n"; flush();
    exit;
}
?>

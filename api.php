<?php
// --- KONFIGURACJA GŁÓWNA ---
set_time_limit(900);
ini_set('max_execution_time', 900);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
ini_set('implicit_flush', 1);
error_reporting(0);
ini_set('display_errors', 0);

$DEEPSEEK_KEY = "sk-f5095ebe51da4b30841efe2faf256745";
$TAVILY_KEY = "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";
$DATA_DIR = __DIR__ . '/data';
$MEMORY_LIMIT = 20000;
$PROJECT_LIMIT = 2;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
if (!file_exists($DATA_DIR)) { mkdir($DATA_DIR, 0755, true); }

// --- HELPERY ---
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) return ['error' => "API Error: $http_code"];
    return json_decode($response, true) ?? ['results' => []];
}

// 2. Simple Scraper (Wchodzenie w linki bezpośrednio)
function simple_scrape($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    // Udajemy przeglądarkę, żeby nas nie zablokowali
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    
    $html = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || empty($html)) return false;

    // Czyścimy HTML do samego tekstu
    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $html);
    $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', "", $html);
    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', $text); // Usuń nadmiar spacji
    
    return mb_substr(trim($text), 0, 15000); // Limit znaków dla modelu
}

// --- LOGIKA ENDPOINTÓW ---
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

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
    $projs[] = ['id' => $new_id, 'name' => $input['name'] ?? 'Nowy'];
    save_json("projects_{$current_user}.json", $projs);
    send_json(['id' => $new_id, 'name' => $input['name']]);
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
    } else {
        $mem[$key] = $val;
    }
    save_json($file, $mem);
    send_json(['status' => 'ok']);
}

if ($action === 'get_sessions') {
    $pid = $_GET['project_id'] ?? '';
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
    $is_new_session = !file_exists($DATA_DIR . "/chat_{$current_user}_{$session_id}.json");

    echo json_encode(['status' => 'ping']) . "\n"; flush();
    if ($is_new_session) { echo json_encode(['status' => 'session_init', 'id' => $session_id]) . "\n"; flush(); }

    // Plik
    $file_content = "";
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $file_content = "\n\n--- ZAŁĄCZNIK: {$_FILES['file']['name']} ---\n" . file_get_contents($_FILES['file']['tmp_name']) . "\n------\n";
    }

    // Pamięć
    $mem = get_json("mem_{$current_user}_{$project_id}.json");
    $memory_context_str = "MEMORY CONTEXT:\n";
    foreach ($mem as $k => $v) { $memory_context_str .= "- {$k}: {$v}\n"; }

    // --- DETEKCJA URL I SCRAPING ---
    $url_context = "";
    $scraped_success = false;
    
    // Szukamy URL w wiadomości
    if (preg_match('/\bhttps?:\/\/[^\s]+/', $message, $matches)) {
        $url_to_scrape = $matches[0];
        echo json_encode(['status' => 'searching', 'msg' => 'Pobieram treść strony...']) . "\n"; flush();
        
        $scraped_content = simple_scrape($url_to_scrape);
        if ($scraped_content) {
            $url_context = "\n\n=== TREŚĆ POBRANA ZE STRONY ($url_to_scrape) ===\n$scraped_content\n=====================================\n";
            $scraped_success = true;
        }
    }

    // --- LOGIKA TAVILY (Jeśli nie scrapujemy lub user wymusił szukanie) ---
    $manual_search = ($_POST['use_search'] ?? '0') === '1';
    $internet_context = "";

    // Jeśli nie udało się pobrać strony (lub nie było URL), a user chce szukać:
    if (($manual_search || preg_match('/(cena|kto|gdzie|kiedy|news)/i', $message)) && !$scraped_success) {
        echo json_encode(['status' => 'searching', 'msg' => 'Szukam w sieci...']) . "\n"; flush();
        $tavily_res = search_tavily($message, $TAVILY_KEY);
        if (!isset($tavily_res['error'])) {
            $internet_context = "\n\n=== WYNIKI WYSZUKIWANIA ===\n";
            if (!empty($tavily_res['results'])) {
                foreach ($tavily_res['results'] as $r) {
                    $internet_context .= "URL: {$r['url']}\nTITLE: {$r['title']}\nCONTENT: {$r['content']}\n---\n";
                }
            } else {
                $internet_context .= "Brak wyników.\n";
            }
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
- **Brak wiedzy:** Jeśli czegoś nie ma w wynikach wyszukiwania ani na stronie, powiedz wprost: "Brak danych w źródłach". Nie halucynuj.
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
    curl_exec($ch);
    curl_close($ch);

    if (!empty($full_bot_response)) {
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
    }
    exit;
}
?>

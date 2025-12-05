<?php
// --- KONFIGURACJA GŁÓWNA ---

// 1. CZAS WYKONANIA (CRITICAL FIX)
set_time_limit(900); 
ini_set('max_execution_time', 900);

// 2. STREAMING (Wyłączamy buforowanie)
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
ini_set('implicit_flush', 1);

// 3. UKRYWANIE BŁĘDÓW PHP (Żeby nie psuły JSON)
error_reporting(0);
ini_set('display_errors', 0);

$DEEPSEEK_KEY = "sk-f5095ebe51da4b30841efe2faf256745";
$TAVILY_KEY = "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";
$DATA_DIR = __DIR__ . '/data';
$MEMORY_LIMIT = 20000;
$PROJECT_LIMIT = 2;
$RETENTION_DAYS = 30;

// CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// Inicjalizacja folderu
if (!file_exists($DATA_DIR)) { mkdir($DATA_DIR, 0755, true); }

// --- HELPERY ---
function get_json($filename) {
    global $DATA_DIR;
    $path = $DATA_DIR . '/' . basename($filename);
    if (file_exists($path)) {
        $content = file_get_contents($path);
        $json = json_decode($content, true);
        return is_array($json) ? $json : [];
    }
    return [];
}

function save_json($filename, $data) {
    global $DATA_DIR;
    file_put_contents($DATA_DIR . '/' . basename($filename), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function send_json($data) {
    // Czyścimy bufor przed wysłaniem JSON (Fix dla Memory)
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function send_error($msg, $code = 400) {
    http_response_code($code);
    send_json(['error' => $msg]);
}

function search_tavily($query, $api_key) {
    $ch = curl_init('https://api.tavily.com/search');
    $data = json_encode([
        'api_key' => $api_key, 
        'query' => $query, 
        'search_depth' => 'basic', 
        'include_answer' => true, 
        'max_results' => 5 
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    // FIX SSL: Wyłączamy sprawdzanie certyfikatów (dla pewności na tanich hostingach)
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    
    $response = curl_exec($ch);
    $error = curl_error($ch); // Łapiemy błąd połączenia
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        return ['error' => "cURL Error: $error"];
    }
    if ($http_code !== 200) {
        return ['error' => "API Error: $http_code. Response: $response"];
    }

    return json_decode($response, true) ?? ['results' => []];
}

// --- LOGIKA ENDPOINTÓW ---
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

// 1. AUTH
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

// MIDDLEWARE
$headers = getallheaders();
$token = $headers['Authorization'] ?? ($_POST['token'] ?? '');
$sessions = get_json('sessions_auth.json');

// Wyjątek dla options
if ($action !== 'chat' && !isset($sessions[$token])) send_error('Auth fail', 401);

// Dla chatu pobieramy usera ręcznie bo to POST form-data
if ($action === 'chat' && isset($_POST['token'])) {
    if(!isset($sessions[$_POST['token']])) send_error('Auth fail', 401);
    $current_user = $sessions[$_POST['token']];
    $should_search = preg_match('/(cena|kurs|news|szukaj|kto|co|gdzie|kiedy|jak|dlaczego|wydarzenia|znajdź|research|pogoda|aktualne|wiedza|statystyki|ile|czy|brand|firma|opinia)/i', $message);
} else {
    $current_user = $sessions[$token] ?? '';
}

// 2. PROJEKTY
if ($action === 'get_projects') {
    $projs = get_json("projects_{$current_user}.json");
    if (empty($projs)) {
        $projs = [['id' => uniqid('p_'), 'name' => 'Projekt Domyślny']];
        save_json("projects_{$current_user}.json", $projs);
    }
    send_json(['projects' => $projs]);
}

if ($action === 'create_project') {
    $projs = get_json("projects_{$current_user}.json");
    if (count($projs) >= $PROJECT_LIMIT) send_error("Limit max $PROJECT_LIMIT projekty.");
    $name = $input['name'] ?? 'Nowy Projekt';
    $new_id = uniqid('p_');
    $projs[] = ['id' => $new_id, 'name' => $name];
    save_json("projects_{$current_user}.json", $projs);
    send_json(['id' => $new_id, 'name' => $name]);
}

// 3. PAMIĘĆ
if ($action === 'get_memory') {
    $pid = $_GET['project_id'] ?? 'default';
    $mem = get_json("mem_{$current_user}_{$pid}.json");
    if (!is_array($mem)) $mem = [];
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
    if (!is_array($mem)) $mem = [];
    
    if ($act === 'delete') {
        if (isset($mem[$key])) unset($mem[$key]);
    } else {
        $temp = $mem; $temp[$key] = $val;
        if (strlen(json_encode($temp)) > $MEMORY_LIMIT) send_error('Limit pamięci!');
        $mem[$key] = $val;
    }
    save_json($file, $mem);
    send_json(['status' => 'ok']);
}

// 4. SESJE
if ($action === 'get_sessions') {
    $pid = $_GET['project_id'] ?? '';
    if (!$pid) send_json([]);
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

if ($action === 'chat') {
    // Czyścimy wszystko, żeby stream nie miał śmieci
    while (ob_get_level()) { ob_end_clean(); }
    
    header('Content-Type: application/x-ndjson');
    header('X-Accel-Buffering: no'); // Dla Nginx
    
    $message = $_POST['message'] ?? '';
    $project_id = $_POST['project_id'] ?? 'default';
    $session_id = $_POST['session_id'] ?? '';
    $is_new_session = empty($session_id);

    if ($is_new_session) $session_id = uniqid('s_');

    // Ping początkowy
    echo json_encode(['status' => 'ping']) . "\n";
    flush();

    if ($is_new_session) {
        echo json_encode(['status' => 'session_init', 'id' => $session_id]) . "\n";
        flush();
    }

    // Załącznik
    $file_content = "";
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $file_content = "\n\n--- PLIK: {$_FILES['file']['name']} ---\n" . file_get_contents($_FILES['file']['tmp_name']) . "\n------\n";
    }

    // Kontekst pamięci
    $mem = get_json("mem_{$current_user}_{$project_id}.json");
    $memory_context_str = "CONTEXT:\n";
    foreach ($mem as $k => $v) { $memory_context_str .= "- {$k}: {$v}\n"; }

    // --- LOGIKA WYSZUKIWANIA (Sterowana przyciskiem) ---
    // Odbieramy flagę '1' (włączony) lub '0' (wyłączony) z JS
    $use_search_flag = $_POST['use_search'] ?? '0'; 
    $should_search = ($use_search_flag === '1');
    
    $internet_context = "";
    
    if ($should_search) {
        echo json_encode(['status' => 'searching']) . "\n"; flush();
        
        $tavily_res = search_tavily($message, $TAVILY_KEY);
        
        if (isset($tavily_res['error'])) {
             $internet_context = "\n\n[SYSTEM ERROR]: Nie udało się przeszukać sieci. Przyczyna: " . $tavily_res['error'];
        } else {
            $internet_context = "\n\n=== WYNIKI WYSZUKIWANIA (Tryb Online Aktywny) ===\n";
            // Dodajemy gotową odpowiedź od Tavily jeśli jest
            if (!empty($tavily_res['answer'])) {
                $internet_context .= "SZYBKIE PODSUMOWANIE: " . $tavily_res['answer'] . "\n\n";
            }
            // Lista wyników
            if (!empty($tavily_res['results'])) {
                foreach ($tavily_res['results'] as $r) {
                    $internet_context .= "SOURCE: [{$r['title']}]({$r['url']})\nCONTENT: {$r['content']}\n---\n";
                }
            } else {
                 $internet_context .= "Brak wyników w API dla tego zapytania.\n";
            }
        }
    }

    // System Prompt
    $system_prompt = "Jesteś Brand OS (Rafi Core). Priorytet: zwięzłość i sens. \n\n" .
                     "1. Masz dostęp do KONTEKSTU marki - używaj go.\n" .
                     "2. Jeśli otrzymasz WYNIKI WYSZUKIWANIA (Tryb Online) - są one nadrzędne wobec twojej wiedzy treningowej. Cytuj źródła.\n" .
                     "3. Jeśli użytkownik nie włączył trybu online (brak wyników), opieraj się na swojej wiedzy i pamięci.\n\n" . 
                     $memory_context_str;

    $messages_for_api = [['role' => 'system', 'content' => $system_prompt]];

    // Historia
    $chat_history = get_json("chat_{$current_user}_{$session_id}.json");
    $recent = array_slice($chat_history, -6);
    foreach ($recent as $h) { $messages_for_api[] = ['role' => $h['role'], 'content' => $h['content']]; }

    $full_msg = $message . $file_content . $internet_context;
    $messages_for_api[] = ['role' => 'user', 'content' => $full_msg];

    // DeepSeek Call
    $ch = curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'deepseek-chat',
        'messages' => $messages_for_api,
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
                    // Output dla JS
                    echo json_encode(['status' => 'content', 'text' => $txt]) . "\n";
                    flush(); // WYMUSZENIE WYSŁANIA PAKIETU
                }
            }
        }
        return strlen($data);
    });

    curl_exec($ch);
    curl_close($ch);

    // Zapisz historię po zakończeniu
    if (!empty($full_bot_response)) {
        $chat_history[] = ['role' => 'user', 'content' => $message . ($file_content ? " [Plik]" : "")];
        $chat_history[] = ['role' => 'assistant', 'content' => $full_bot_response];
        save_json("chat_{$current_user}_{$session_id}.json", $chat_history);

        // Update listy sesji
        $list_file = "sessions_list_{$current_user}_{$project_id}.json";
        $sessions_list = get_json($list_file);
        $exists = false;
        foreach ($sessions_list as &$s) {
            if ($s['id'] === $session_id) { $s['updated_at'] = date('Y-m-d H:i:s'); $exists = true; break; }
        }
        if (!$exists) {
            $title = mb_substr($message, 0, 30) . '...';
            $sessions_list[] = ['id' => $session_id, 'title' => $title, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
        }
        save_json($list_file, $sessions_list);
    }
    
    exit;
}
?>
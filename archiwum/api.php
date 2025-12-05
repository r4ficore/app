<?php
// --- KONFIGURACJA ---
$DEEPSEEK_KEY = "TU_WSTAWIE_KLUCZ";
$TAVILY_KEY = "TU_WSTAWIE_KLUCZ";
$DATA_DIR = __DIR__ . '/data';
$MEMORY_LIMIT = 20000;
$PROJECT_LIMIT = 2; // Limit projektów
$RETENTION_DAYS = 30; // Ile dni trzymać czaty

ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
ini_set('implicit_flush', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
if (!file_exists($DATA_DIR)) { mkdir($DATA_DIR, 0755, true); }

// --- FUNKCJE POMOCNICZE ---

function get_json($filename) {
    global $DATA_DIR;
    $path = $DATA_DIR . '/' . basename($filename);
    if (file_exists($path)) return json_decode(file_get_contents($path), true) ?? [];
    return [];
}

function save_json($filename, $data) {
    global $DATA_DIR;
    file_put_contents($DATA_DIR . '/' . basename($filename), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function search_tavily($query, $api_key) {
    $ch = curl_init('https://api.tavily.com/search');
    $data = json_encode(['api_key' => $api_key, 'query' => $query, 'search_depth' => 'basic', 'max_results' => 3]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res['results'] ?? [];
}

// --- LOGIKA ---

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

// AUTH
if ($action === 'register') {
    $users = get_json('users.json');
    $u = strtolower(trim($input['username']));
    if (isset($users[$u])) { echo json_encode(['error' => 'Login zajęty']); exit; }
    $users[$u] = password_hash($input['password'], PASSWORD_DEFAULT);
    save_json('users.json', $users);
    $def_proj_id = uniqid('p_');
    save_json("projects_{$u}.json", [['id' => $def_proj_id, 'name' => 'Mój Pierwszy Projekt']]);
    echo json_encode(['message' => 'OK']); exit;
}

if ($action === 'login') {
    $users = get_json('users.json');
    $u = strtolower(trim($input['username']));
    if (isset($users[$u]) && password_verify($input['password'], $users[$u])) {
        $token = bin2hex(random_bytes(16));
        $sessions = get_json('sessions_auth.json');
        $sessions[$token] = $u;
        save_json('sessions_auth.json', $sessions);
        echo json_encode(['token' => $token, 'username' => $u]);
    } else { http_response_code(401); echo json_encode(['error' => 'Błąd logowania']); }
    exit;
}

// MIDDLEWARE
$headers = getallheaders();
$token = $headers['Authorization'] ?? ($_POST['token'] ?? '');
$sessions = get_json('sessions_auth.json');
if (!isset($sessions[$token])) { http_response_code(401); echo json_encode(['error' => 'Auth fail']); exit; }
$current_user = $sessions[$token];

// PROJEKTY
if ($action === 'get_projects') {
    $projs = get_json("projects_{$current_user}.json");
    if (empty($projs)) {
        $projs = [['id' => uniqid('p_'), 'name' => 'Projekt Domyślny']];
        save_json("projects_{$current_user}.json", $projs);
    }
    echo json_encode(['projects' => $projs]); exit;
}

if ($action === 'create_project') {
    $projs = get_json("projects_{$current_user}.json");
    // OGRANICZENIE: MAX 2 PROJEKTY
    if (count($projs) >= $PROJECT_LIMIT) {
        http_response_code(400); echo json_encode(['error' => "Limit osiągnięty. Max $PROJECT_LIMIT projekty w wersji testowej."]); exit;
    }
    $name = $input['name'] ?? 'Nowy Projekt';
    $new_id = uniqid('p_');
    $projs[] = ['id' => $new_id, 'name' => $name];
    save_json("projects_{$current_user}.json", $projs);
    echo json_encode(['id' => $new_id, 'name' => $name]); exit;
}

// SESJE (Z CLEANUPEM 30 DNI)
if ($action === 'get_sessions') {
    $pid = $_GET['project_id'] ?? '';
    if (!$pid) { echo json_encode([]); exit; }
    
    $sessions = get_json("sessions_list_{$current_user}_{$pid}.json");
    
    // --- AUTOMATYCZNE CZYSZCZENIE ---
    $now = time();
    $cutoff = $now - ($RETENTION_DAYS * 24 * 60 * 60);
    $cleaned_sessions = [];
    $removed_count = 0;

    foreach ($sessions as $s) {
        $updated = strtotime($s['updated_at']);
        if ($updated > $cutoff) {
            $cleaned_sessions[] = $s;
        } else {
            // Usuń stary plik czatu
            $chat_file = $DATA_DIR . '/' . basename("chat_{$current_user}_{$s['id']}.json");
            if (file_exists($chat_file)) unlink($chat_file);
            $removed_count++;
        }
    }

    // Jeśli coś usunięto, zapisz nową listę
    if ($removed_count > 0) {
        save_json("sessions_list_{$current_user}_{$pid}.json", $cleaned_sessions);
    }

    // Sortowanie
    usort($cleaned_sessions, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));
    echo json_encode(['sessions' => $cleaned_sessions]); exit;
}

if ($action === 'get_chat_history') {
    $sid = $_GET['session_id'] ?? '';
    echo json_encode(['history' => get_json("chat_{$current_user}_{$sid}.json")]); exit;
}

if ($action === 'delete_session') {
    $sid = $input['session_id'];
    $pid = $input['project_id'];
    $list = get_json("sessions_list_{$current_user}_{$pid}.json");
    $list = array_filter($list, fn($s) => $s['id'] !== $sid);
    save_json("sessions_list_{$current_user}_{$pid}.json", array_values($list));
    $path = $DATA_DIR . '/' . basename("chat_{$current_user}_{$sid}.json");
    if (file_exists($path)) unlink($path);
    echo json_encode(['status' => 'ok']); exit;
}

// PAMIĘĆ
if ($action === 'get_memory') {
    $pid = $_GET['project_id'] ?? 'default';
    $mem = get_json("mem_{$current_user}_{$pid}.json");
    $chars = strlen(json_encode($mem));
    $percent = min(100, intval(($chars / $MEMORY_LIMIT) * 100));
    echo json_encode(['data' => $mem, 'usage' => $percent, 'limit' => $MEMORY_LIMIT]); exit;
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
        $temp = $mem; $temp[$key] = $val;
        if (strlen(json_encode($temp)) > $MEMORY_LIMIT) {
            http_response_code(400); echo json_encode(['error' => 'Limit pamięci!']); exit;
        }
        $mem[$key] = $val;
    }
    save_json($file, $mem);
    echo json_encode(['status' => 'ok']); exit;
}

// CZAT
if ($action === 'chat') {
    $message = $_POST['message'] ?? '';
    $project_id = $_POST['project_id'] ?? 'default';
    $session_id = $_POST['session_id'] ?? '';
    $is_new_session = empty($session_id);

    if ($is_new_session) $session_id = uniqid('s_');

    $file_content = "";
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $file_content = "\n\n--- ZAŁĄCZNIK: {$_FILES['file']['name']} ---\n" . file_get_contents($_FILES['file']['tmp_name']) . "\n------\n";
    }

    // NAPRAWA PAMIĘCI: Pobieranie z poprawnego pliku projektu
    $mem = get_json("mem_{$current_user}_{$project_id}.json");
    $memory_context_str = "";
    if (!empty($mem)) {
        $memory_context_str = "DANE MARKI/PROJEKTU (PRIORYTETOWE):\n";
        foreach ($mem as $k => $v) {
            $memory_context_str .= "- [{$k}]: {$v}\n";
        }
    } else {
        $memory_context_str = "Brak zapisanych danych o marce.";
    }

    $chat_history = get_json("chat_{$current_user}_{$session_id}.json");
    
    // Budowanie promptu systemowego z mocnym naciskiem na pamięć
    $messages_for_api = [
        ['role' => 'system', 'content' => "Jesteś asystentem biznesowym.\n\n$memory_context_str\n\nUżywaj powyższych danych o marce w każdej odpowiedzi, jeśli są relewantne."]
    ];
    
    $recent_history = array_slice($chat_history, -8);
    foreach ($recent_history as $h) {
        $messages_for_api[] = ['role' => $h['role'], 'content' => $h['content']];
    }

    $should_search = preg_match('/(cena|kurs|news|szukaj|kto to|wydarzenia|znajdź|research)/i', $message);

    header('Content-Type: application/x-ndjson');
    echo str_pad('', 4096) . "\n"; flush();

    if ($is_new_session) {
        echo json_encode(['status' => 'session_init', 'id' => $session_id]) . "\n"; flush();
    }

    $internet_context = "";
    if ($should_search) {
        echo json_encode(['status' => 'searching', 'text' => "🔍 Szukam..."]) . "\n"; flush();
        $res = search_tavily($message, $TAVILY_KEY);
        $internet_context = "\n\nWYNIKI WEB:\n" . json_encode($res, JSON_UNESCAPED_UNICODE);
    }

    echo json_encode(['status' => 'generating', 'text' => ""]) . "\n"; flush();

    $full_msg = $message . $file_content . $internet_context;
    $messages_for_api[] = ['role' => 'user', 'content' => $full_msg];

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
                    echo json_encode(['status' => 'content', 'text' => $txt]) . "\n";
                    flush();
                }
            }
        }
        return strlen($data);
    });

    curl_exec($ch);
    curl_close($ch);

    // Zapis historii
    $chat_history[] = ['role' => 'user', 'content' => $message . ($file_content ? " [Załącznik]" : "")];
    $chat_history[] = ['role' => 'assistant', 'content' => $full_bot_response];
    save_json("chat_{$current_user}_{$session_id}.json", $chat_history);

    $list_file = "sessions_list_{$current_user}_{$project_id}.json";
    $sessions_list = get_json($list_file);
    
    $exists = false;
    foreach ($sessions_list as &$s) {
        if ($s['id'] === $session_id) { $s['updated_at'] = date('Y-m-d H:i:s'); $exists = true; break; }
    }
    
    if (!$exists) {
        $title = mb_substr($message, 0, 25) . '...';
        $sessions_list[] = ['id' => $session_id, 'title' => $title, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
    }
    save_json($list_file, $sessions_list);
    exit;
}
?>

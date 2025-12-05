<?php
// debug.php - Test połączenia z DeepSeek na Zenbox
header('Content-Type: text/plain');

echo "--- TEST DIAGNOSTYCZNY ---\n";
echo "PHP Version: " . phpversion() . "\n";
echo "cURL Version: " . curl_version()['version'] . "\n";
echo "Host OS: " . PHP_OS . "\n\n";

$urls = [
    'GOOGLE (Test IPv4)' => 'https://www.google.com',
    'DEEPSEEK API' => 'https://api.deepseek.com/chat/completions'
];

foreach ($urls as $name => $url) {
    echo "Testing: $name ($url)...\n";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Wymuszamy IPv4
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    // Ustawiamy timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    // Wyłączamy weryfikację SSL (tylko do testu, żeby wykluczyć błędy certyfikatów)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Udajemy przeglądarkę
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    
    $result = curl_exec($ch);
    $error = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($error) {
        echo "[BŁĄD]: $error\n";
    } else {
        echo "[SUKCES]: HTTP Code " . $info['http_code'] . "\n";
        echo "Resolved IP: " . $info['primary_ip'] . "\n";
    }
    echo "--------------------------\n";
}
?>
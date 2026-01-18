<?php
header('Content-Type: application/json');

// Configuração
$logDir = __DIR__ . '/logs';
$trackingMapFile = $logDir . '/tracking_map.json';
$unsubscribeLogsFile = $logDir . '/unsubscribe_logs.json';
$webhookUrl = 'https://eotz4wvybe4cndl.m.pipedream.net'; // SUBSTITUA AQUI

// Verifica modo de teste
$testMode = isset($_GET['test_mode']) || isset($_POST['test_mode']);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ============================================================
// AÇÃO: BUSCAR EMAIL PELO TRACKING ID
// ============================================================
if ($action === 'get_email') {
    $trackingId = $_GET['id'] ?? '';
    
    if (empty($trackingId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID não fornecido']);
        exit;
    }
    
    if (!file_exists($trackingMapFile)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Mapa não encontrado']);
        exit;
    }
    
    $trackingMap = json_decode(file_get_contents($trackingMapFile), true) ?: [];
    $email = $trackingMap[$trackingId] ?? null;
    
    if (!$email) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Email não encontrado']);
        exit;
    }
    
    echo json_encode(['success' => true, 'email' => $email]);
    exit;
}

// ============================================================
// AÇÃO: SALVAR PREFERÊNCIAS
// ============================================================
if ($action === 'save_preferences') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $trackingId = $input['tracking_id'] ?? '';
    $email = $input['email'] ?? '';
    $comunicacao = $input['comunicacao'] ?? [];
    $pausa = $input['pausa'] ?? 'Sem pausa';
    $unsubscribed = $input['unsubscribed'] ?? false;
    $telefoneGerente = $input['telefone_gerente'] ?? null; // NOVO CAMPO
    
    $logEntry = [
        'event' => 'preferences',
        'tracking_id' => $trackingId,
        'email' => $email,
        'comunicacao' => $comunicacao,
        'pausa' => $pausa,
        'unsubscribed' => $unsubscribed,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'timestamp' => date('c'),
        'test_mode' => $testMode
    ];
    
    // Adiciona telefone do gerente se fornecido
    if ($telefoneGerente) {
        $logEntry['phone'] = $telefoneGerente;
    }
    
    // Salva log local
    $logs = [];
    if (file_exists($unsubscribeLogsFile)) {
        $content = file_get_contents($unsubscribeLogsFile);
        $logs = json_decode($content, true) ?: [];
    }
    $logs[] = $logEntry;
    file_put_contents($unsubscribeLogsFile, json_encode($logs, JSON_PRETTY_PRINT));
    
    // Envia webhook (se não for teste)
    if (!$testMode && $webhookUrl) {
        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($logEntry));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'User-Agent: Lenovo-Unsubscribe/1.0'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        curl_exec($ch);
        curl_close($ch);
    }
    
    echo json_encode(['success' => true, 'message' => 'Preferências salvas']);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Ação inválida']);
?>
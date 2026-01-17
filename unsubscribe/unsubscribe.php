<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Responde a requisições OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Arquivos de dados
$trackingMapFile = __DIR__ . '/logs/tracking_map.json';
$unsubscribeLogFile = __DIR__ . '/logs/unsubscribe_logs.json';

// ============================================================================
// FUNÇÃO: GET EMAIL POR TRACKING ID
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_email') {
    $trackingId = $_GET['id'] ?? null;
    
    if (!$trackingId) {
        echo json_encode(['error' => 'Missing tracking ID']);
        http_response_code(400);
        exit;
    }
    
    // Carrega mapa de tracking IDs para emails
    if (file_exists($trackingMapFile)) {
        $trackingMap = json_decode(file_get_contents($trackingMapFile), true);
        
        if (isset($trackingMap[$trackingId])) {
            echo json_encode([
                'success' => true,
                'email' => $trackingMap[$trackingId]['email'],
                'sent_at' => $trackingMap[$trackingId]['sent_at']
            ]);
            exit;
        }
    }
    
    echo json_encode(['error' => 'Tracking ID not found']);
    http_response_code(404);
    exit;
}

// ============================================================================
// FUNÇÃO: REGISTRAR DESCADASTRO
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lê dados do POST
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        echo json_encode(['error' => 'Invalid JSON']);
        http_response_code(400);
        exit;
    }
    
    // Valida dados obrigatórios
    if (empty($data['email'])) {
        echo json_encode(['error' => 'Email is required']);
        http_response_code(400);
        exit;
    }
    
    // Prepara log entry
    $logEntry = [
        'id' => $data['id'] ?? null,
        'email' => $data['email'],
        'preferences' => $data['preferences'] ?? [],
        'timestamp' => $data['timestamp'] ?? date('c'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'processed_at' => date('c')
    ];
    
    // Carrega logs existentes
    $logs = [];
    if (file_exists($unsubscribeLogFile)) {
        $content = file_get_contents($unsubscribeLogFile);
        $logs = json_decode($content, true) ?: [];
    }
    
    // Adiciona novo log
    $logs[] = $logEntry;
    
    // Salva logs
    $saved = file_put_contents(
        $unsubscribeLogFile, 
        json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
    
    if ($saved !== false) {
        echo json_encode([
            'success' => true,
            'message' => 'Preferences saved successfully',
            'id' => $data['id']
        ]);
        http_response_code(200);
    } else {
        echo json_encode(['error' => 'Failed to save data']);
        http_response_code(500);
    }
    
    exit;
}

// Método não suportado
echo json_encode(['error' => 'Method not allowed']);
http_response_code(405);
?>
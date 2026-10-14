<?PHP
require_once "base.php";

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/json');

function sendResponse($success, $data = [], $message = '') {
    $data = json_decode(json_encode($data, JSON_INVALID_UTF8_IGNORE), true);
    $out = [
        'success' => $success,
        'data' => $data,
        'message' => (string)$message
    ];
    $json = json_encode($out);
    if ($json === false) {
        $json = json_encode(['success'=>false, 'message'=>'JSON Encode Failed: ' . json_last_error_msg()]);
    }
    echo $json;
    exit;
}

$action = $_GET['action'] ?? '';

// === 获取配置 ===
if ($action == 'get_config') {
    $content = file_exists($CONFIG_YAML) ? file_get_contents($CONFIG_YAML) : "";
    sendResponse(true, ['content' => $content], 'Loaded');
}

// === 保存配置 ===
if ($action == 'save_config') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, [], 'Method Not Allowed');
    
    $newContent = $_POST['content'] ?? '';
    
    if (empty($newContent) && !empty(file_get_contents('php://input'))) {
         $input = json_decode(file_get_contents('php://input'), true);
         $newContent = $input['content'] ?? '';
         $target_enabled = $input['enabled'] ?? '';
    } else {
         $target_enabled = $_POST['enabled'] ?? '';
    }
    
    if (empty($newContent)) sendResponse(false, [], 'Content cannot be empty');

    // 1. Binary-Native Pre-flight Validation (PRO GRADE & ISOLATED)
    $tempFile = $PLG_PATH . "/config_validate.yaml";
    file_put_contents($tempFile, $newContent);
    
    // VALIDATE command now forces dummy database/port/logs
    $validation_msg = trim(shell_exec("bash $DAEMON_SCRIPT 'VALIDATE' " . escapeshellarg($tempFile)));
    @unlink($tempFile);
    
    if (!empty($validation_msg)) {
        sendResponse(false, ['details' => $validation_msg], "Binary Validation Failed:\n" . $validation_msg);
    }

    // 2. Proved OK: Save permanent YAML content
    if (file_put_contents($CONFIG_YAML, $newContent) === false) sendResponse(false, [], 'Write failed');

    // 3. Sync 'Enabled/Disabled' radio state if provided
    if ($target_enabled !== '') {
        setSettingValue('filebrowser_ENABLED', $target_enabled);
    }
    
    $is_enabled = ($target_enabled !== '') ? ($target_enabled == 'true') : (getSettingValue('filebrowser_ENABLED', 'false') == 'true');
    $restarted = false;
    
    // 4. Delegate Lifecycle to Daemon.sh (ASYNCHRONOUS RESTART)
    if ($is_enabled) {
         shell_exec("bash $DAEMON_SCRIPT 'RESTART' > /dev/null 2>&1 &");
         $restarted = true;
    } else {
         shell_exec("bash $DAEMON_SCRIPT 'STOP_ONLY' > /dev/null 2>&1 &");
    }
    sendResponse(true, ['restarted' => $restarted], 'Saved successfully and passed binary validation.');
}

// === 获取状态 ===
if ($action == 'get_status') {
    $is_enabled = getSettingValue('filebrowser_ENABLED', 'false') == 'true' ? 'true' : 'false';
    
    $port = exec($DAEMON_SCRIPT . ' "GET_PORT"');
    
    // Delegation: Use specialized CHECK command for process status
    $check_output = trim(shell_exec("bash $DAEMON_SCRIPT 'CHECK'"));
    $running = ($check_output === 'running' || $check_output === 'fully_ready');
    $ready = ($check_output === 'fully_ready');
    
    $local_ver = exec($DAEMON_SCRIPT . ' "GET_LOCAL_VER"');
    $latest_ver = getSettingValue('filebrowser_LATEST', 'Unknown');
    
    $branch = getSettingValue('filebrowser_BRANCH', 'stable');
    $logfile = getLogPath($CONFIG_YAML);

    sendResponse(true, [
        'enabled' => $is_enabled,
        'running' => $running,
        'ready' => $ready,
        'port' => $port,
        'branch' => $branch == 'beta' ? '2' : '1',
        'local_version' => trim($local_ver),
        'latest_version' => trim($latest_ver),
        'log_exists' => file_exists($logfile)
    ], 'Status fetched');
}

// === 设置服务状态 ===
if ($action == 'set_state') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, [], 'Method Not Allowed');
    
    $target_state = $_POST['enabled'] ?? '';
    
    if ($target_state !== 'true' && $target_state !== 'false') {
        sendResponse(false, [], 'Invalid state');
    }

    shell_exec($DAEMON_SCRIPT . ' ' . escapeshellarg($target_state) . " > /dev/null 2>&1 &");
    
    sendResponse(true, ['target' => $target_state], 'Command sent');
}

// === 检查更新 ===
if ($action == 'check_update') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, [], 'Method Not Allowed');
    
    $branch = $_POST['branch'] ?? '';
    
    // 1 = stable, 2 = beta
    $branchName = ($branch === '2') ? 'beta' : 'stable';
    
    // Delegate to Daemon.sh to ensure both settings.cfg AND BETA_MARKER are synced
    shell_exec($DAEMON_SCRIPT . ' "SET_BRANCH" ' . escapeshellarg($branchName));
    
    $latest = trim(shell_exec($DAEMON_SCRIPT . ' "VERSION"')) ?: "Unknown";
    
    sendResponse(true, ['latest_version' => $latest], 'Version checked');
}

// === 清空日志 ===
if ($action == 'clear_log') {
    $logfile = getLogPath($CONFIG_YAML);
    if (file_exists($logfile)) {
        file_put_contents($logfile, '');
        sendResponse(true, [], 'Log cleared');
    } else {
        sendResponse(false, [], 'Log file not found');
    }
}

sendResponse(false, [], 'Invalid action: ' . $action);
?>

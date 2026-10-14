<?php
// v2.0.4 - SSE Debug Edition
require_once "base.php";

// 彻底禁用输出缓存
while (ob_get_level()) ob_end_clean();
set_time_limit(0);
ignore_user_abort(false);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // [关键] 告诉 Unraid 的 Nginx 不要缓存这个流
header('Content-Encoding: none'); // [关键] 防止 Gzip 压缩导致缓冲

$logfile = getLogPath($CONFIG_YAML);

// 调试点 1: 检查文件权限和路径
if (!file_exists($logfile)) {
    echo "data: [Error] 无法找到日志文件: $logfile \n\n";
    flush();
    exit;
}

if (!is_readable($logfile)) {
    echo "data: [Error] PHP 无权读取日志 (nobody用户权限不足)\n\n";
    flush();
    exit;
}

$level = $_GET['level'] ?? 'all';
$cmd = "stdbuf -oL tail -n 50 -f " . escapeshellarg($logfile);
if ($level !== 'all') {
    // 强制 grep 也不要缓存
    $cmd .= " | stdbuf -oL grep -i --line-buffered " . escapeshellarg($level);
}

// 调试点 2: 显式告知连接成功
echo "data: [System] 已连接到日志: " . basename($logfile) . " (Level: " . strtoupper($level) . ")\n\n";
flush();

$descriptorspec = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
$process = proc_open($cmd, $descriptorspec, $pipes);

if (is_resource($process)) {
    stream_set_blocking($pipes[1], 0);

    while (!connection_aborted()) {
        $line = fgets($pipes[1]);
        if ($line !== false && $line !== "") {
            echo "data: " . htmlspecialchars(trim($line)) . "\n\n";
            flush();
        } else {
            // 心跳：一定要发送，防止 Nginx 超时断开
            echo ": heartbeat\n\n";
            flush();
            usleep(300000); 
        }
    }

    // 彻底清理
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_terminate($process, 9);
    proc_close($process);
} else {
    echo "data: [Error] 无法启动 tail 进程\n\n";
    flush();
}
?>

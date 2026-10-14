<?PHP
// 读取统一配置文件
$conf = "/usr/local/emhttp/plugins/filebrowser_quantum/paths.conf";
$paths = file_exists($conf) ? parse_ini_file($conf) : [];

// 核心路径变量
$DAEMON_SCRIPT = $paths['DAEMON_SCRIPT'] ?? "/usr/local/emhttp/plugins/filebrowser_quantum/Daemon.sh";
$BINARY        = $paths['BINARY']        ?? "/usr/sbin/filebrowser_quantumorig";
$PLG_PATH      = $paths['PLG_PATH']      ?? "/boot/config/plugins/filebrowser_quantum";
$CONFIG_YAML   = $paths['CONFIG_YAML']   ?? "$PLG_PATH/config.yaml";
$SETTINGS_FILE = $paths['SETTINGS_FILE'] ?? "$PLG_PATH/settings.cfg";
$INSTALL_PATH  = $paths['INSTALL_PATH']  ?? "$PLG_PATH/install";

/**
 * 读取 settings.cfg 中的配置项
 */
function getSettingValue($key, $default = '') {
    global $SETTINGS_FILE;
    if (!file_exists($SETTINGS_FILE)) return $default;
    $settings = @parse_ini_file($SETTINGS_FILE);
    return $settings[$key] ?? $default;
}

/**
 * 写入 settings.cfg 中的配置项
 */
function setSettingValue($key, $value) {
    global $SETTINGS_FILE;
    if (!file_exists($SETTINGS_FILE)) touch($SETTINGS_FILE);
    
    $content = file_get_contents($SETTINGS_FILE);
    $pattern = "/^" . preg_quote($key, '/') . "=.*/m";
    $newLine = $key . '=' . (is_numeric($value) ? $value : '"' . $value . '"');
    
    if (preg_match($pattern, $content)) {
        $content = preg_replace($pattern, $newLine, $content);
    } else {
        $content .= "\n" . $newLine;
    }
    
    file_put_contents($SETTINGS_FILE, trim($content) . "\n");
}

/**
 * 提取日志文件路径
 */
function getLogPath($configFile, $content = null) {
    $default = "/var/log/filebrowser_quantum.log";
    if ($content === null && file_exists($configFile)) {
        $content = file_get_contents($configFile);
    }
    if ($content) {
        if (preg_match('/output:\s*["\']?([^"\']+)["\']?/', $content, $matches)) {
            return trim($matches[1]);
        }
    }
    return $default;
}

/**
 * 从 config.yaml 中轻量读取键值（正则逐行解析，避免依赖 YAML 扩展）
 * 匹配形如 "  key: value" 的行，自动去除引号；键不存在或为空时返回默认值
 */
function getConfigYamlValue($key, $default = '') {
    global $CONFIG_YAML;
    if (!file_exists($CONFIG_YAML)) return $default;
    $content = @file_get_contents($CONFIG_YAML);
    if (!$content) return $default;
    if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*:\s*(.+)$/m', $content, $matches)) {
        $val = trim(trim($matches[1]), "\"'");
        return $val !== '' ? $val : $default;
    }
    return $default;
}

/**
 * 从 config.yaml 中读取指定顶层段（如 http:）下的键值
 * 与 getConfigYamlValue() 的区别：只在目标段内匹配，不会跨段命中同名键
 */
function getConfigYamlSectionValues($section) {
    global $CONFIG_YAML;
    $values = [];
    if (!file_exists($CONFIG_YAML)) return $values;
    $content = @file_get_contents($CONFIG_YAML);
    if (!$content) return $values;

    $sec = preg_quote(trim($section, " :\t\n\r\0\x0B"), '/');
    // 目标段从 "http:" 行开始，到下一个顶层键（行首无缩进的 key:）或文件结尾为止
    if (!preg_match('/^' . $sec . '\s*:(.*?)(?=^\S|\z)/ms', $content, $m)) return $values;

    foreach (preg_split('/\r\n|\r|\n/', $m[1]) as $line) {
        if (!preg_match('/^\s+(.+?)\s*:\s*(.*)$/', $line, $kv)) continue;
        $key = trim($kv[1]);
        $val = trim($kv[2]);
        // 去掉行尾注释（引号内的 # 不受影响）
        $val = preg_replace('/\s+#.*$/', '', $val);
        $values[$key] = trim($val, "\"'");
    }
    return $values;
}

/**
 * 读取 http: 段配置（port / baseURL / internalUrl / externalUrl ...）
 * 未在 $defaults 中声明、但 config.yaml 里存在的键也会一并返回
 */
function getHttpConfig($defaults = []) {
    $http = getConfigYamlSectionValues('http');
    $out = [];
    foreach (array_merge($defaults, $http) as $key => $default) {
        $val = array_key_exists($key, $http) ? $http[$key] : $default;
        $out[$key] = ($val === '' && array_key_exists($key, $defaults)) ? $defaults[$key] : $val;
    }
    return $out;
}

/**
 * 取 FileBrowser 监听端口：优先 config.yaml 的 http.port，
 * 其次沿用 Daemon.sh GET_PORT，最后回退 8081
 */
function getConfigPort($fallback = '8081') {
    $http = getHttpConfig();
    if (isset($http['port']) && is_numeric($http['port'])) {
        return (string)intval($http['port']);
    }
    if (file_exists(getDaemonScript()) && function_exists('exec')) {
        // escapeshellarg 已把路径整体引号化，不能再追加重定向（会被当成参数）
        $port = trim((string)@exec(escapeshellarg(getDaemonScript()) . ' GET_PORT'));
        if ($port !== '' && is_numeric($port)) return (string)intval($port);
    }
    return $fallback;
}

/**
 * Daemon.sh 路径（与 base.php 顶部保持一致，供函数内使用）
 */
function getDaemonScript() {
    global $DAEMON_SCRIPT;
    return $DAEMON_SCRIPT;
}

/**
 * 当前请求的协议：X-Forwarded-Proto → HTTPS → SERVER_PORT=443 → http
 * 反向代理（NPM / SWAG / Traefik / Cloudflare）通常只给 X-Forwarded-Proto
 */
function getRequestScheme() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        if ($proto === 'https' || $proto === 'http') return $proto;
    }
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return 'https';
    if (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') return 'https';
    return 'http';
}

/**
 * 当前请求的主机名（不含端口）
 * X-Forwarded-Host 优先，回退 HTTP_HOST；兼顾 IPv6 方括号写法与逗号分隔的多级代理
 */
function getRequestHost() {
    $host = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $host = trim((string)$_SERVER['HTTP_HOST']);
    } elseif (!empty($_SERVER['SERVER_NAME'])) {
        $host = trim((string)$_SERVER['SERVER_NAME']);
    }
    if ($host === '') return 'localhost';
    // [::1]:8081 / nas.example.com:8081 / nas.example.com / [::1]
    if (preg_match('/^(\[[^\]]*\]|[^:]+)(?::\d+)?$/', $host, $m)) return $m[1];
    return rtrim($host, ':');
}

/**
 * 当前请求的端口
 * 优先级：Host 头中显式端口 → X-Forwarded-Port → null（表示默认端口）
 */
function getRequestPort() {
    $hostHeader = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $hostHeader = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $hostHeader = trim((string)$_SERVER['HTTP_HOST']);
    }
    if (preg_match('/^\[[^\]]*\]|^[^:]+:(\d+)$/', $hostHeader, $m) && !empty($m[1])) {
        return $m[1];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PORT'])) {
        $port = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PORT'])[0]);
        if (ctype_digit($port)) return $port;
    }
    return null;
}

/**
 * 是否为内网主机
 *   私有网段 / localhost / IPv6 本地地址（127/8、10/8、172.16/12、192.168/16、169.254/16、
 *   ::1、fe80::/10、fc00::/7）
 *   以及内网常用主机名（本机名、tower、单标签主机名、*.local / *.lan / *.home.arpa）
 */
function isLanHost($host) {
    if ($host === '') return false;
    if (preg_match(
        '/^(localhost|127\.|10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2[0-9]|3[01])\.|\[?::1\]?$|\[?fe80:|\[?f[cd])/i',
        $host
    )) {
        return true;
    }
    $name = strtolower(trim($host, '[]'));
    if (strpos($name, '.') === false) return true;               // 单标签主机名：nas / tower / unraid
    $local = strtolower((string)@gethostname());
    if ($local !== '' && $name === $local) return true;          // Unraid 本机名（可能带 .local 后缀）
    if (substr($name, -6) === '.local') return true;             // mDNS
    if (substr($name, -4) === '.lan') return true;
    if (substr($name, -5) === '.home') return true;
    if (substr($name, -10) === '.home.arpa') return true;
    return false;
}

/**
 * 拼装 FileBrowser 访问地址
 *
 * 优先级：
 *   1. 内网访问且配置了 http.internalUrl  → 用 internalUrl
 *   2. 非内网访问且配置了 http.externalUrl → 用 externalUrl
 *   3. 按当前请求推导：外网走反代域名+反代端口，内网走内网主机+FileBrowser 端口；
 *      协议与端口取自反代头（X-Forwarded-Proto / X-Forwarded-Port），避免 http/https 混用
 *
 * 返回：url / source / trustProxyHeaders / isLan / port / externalUrl / internalUrl / baseURL
 */
function getFileBrowserNav() {
    $http        = getHttpConfig();
    $port        = getConfigPort();
    $baseURL     = isset($http['baseURL']) ? trim($http['baseURL']) : '/';
    $internalUrl = isset($http['internalUrl']) ? rtrim($http['internalUrl'], '/') : '';
    $externalUrl = isset($http['externalUrl']) ? rtrim($http['externalUrl'], '/') : '';
    $trustProxy  = strtolower(isset($http['trustProxyHeaders']) ? $http['trustProxyHeaders'] : '') === 'true';

    $scheme   = getRequestScheme();
    $host     = getRequestHost();
    $portReq  = getRequestPort();
    $isLan    = isLanHost($host);
    $hostPart = (strpos($host, ':') !== false && $host[0] !== '[') ? "[$host]" : $host;

    $url    = '';
    $source = '';

    if ($isLan && $internalUrl !== '') {
        $url    = $internalUrl;
        $source = 'config:http.internalUrl';
    } elseif (!$isLan && $externalUrl !== '') {
        $url    = $externalUrl;
        $source = 'config:http.externalUrl';
    } elseif ($isLan) {
        // 内网直连：改用「当前 Unraid 页面」的协议，避免 https 页面里嵌 http iframe 被浏览器拦截
        $url    = "{$scheme}://{$hostPart}:{$port}";
        $source = 'auto:request+lan';
    } else {
        $defaultPort = ($scheme === 'https') ? '443' : '80';
        $usePort     = ($portReq !== null && $portReq !== '' && $portReq !== $defaultPort) ? ":{$portReq}" : '';
        $url         = "{$scheme}://{$hostPart}{$usePort}";
        $source      = 'auto:request+proxy';
    }

    if ($baseURL !== '' && $baseURL !== '/' && substr($url, -strlen($baseURL)) !== $baseURL) {
        $url = rtrim($url, '/') . '/' . ltrim($baseURL, '/');
    }

    return [
        'url'               => $url,
        'source'            => $source,
        'trustProxyHeaders' => $trustProxy,
        'isLan'             => $isLan,
        'scheme'            => $scheme,
        'host'              => $host,
        'port'              => $port,
        'requestPort'       => $portReq,
        'externalUrl'       => $externalUrl,
        'internalUrl'       => $internalUrl,
        'baseURL'           => $baseURL,
    ];
}

/**
 * 判断父页面（Unraid WebUI）协议与 iframe 地址协议是否混用
 * 混用会被浏览器按 mixed content 拦截，表现为「标签页空白 / 无法访问」
 */
function isMixedContentUrl($url, $pageScheme) {
    $parts = @parse_url($url);
    if (!$parts || empty($parts['scheme'])) return false;
    return $pageScheme === 'https' && strtolower($parts['scheme']) === 'http';
}

/**
 * 动态提取日志等级列表
 */
function getLogLevels($configFile, $content = null) {
    if ($content === null && file_exists($configFile)) {
        $content = file_get_contents($configFile);
    }
    if ($content) {
        if (preg_match('/levels:\s*["\']?([^"\']+)["\']?/', $content, $matches)) {
            $levels = explode('|', trim($matches[1]));
            return array_map('trim', array_filter($levels));
        }
    }
    return ['info', 'error'];
}
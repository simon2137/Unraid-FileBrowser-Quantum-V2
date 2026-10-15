<?php
/**
 * base.php URL 分流逻辑的离线测试（Windows 开发机可直接跑）
 *   php .diag/fb_url_test.php
 *
 * 覆盖场景：外网 https 反代（externalUrl 空 / 已配置 / 非 443 端口）、
 *          内网 http、内网 https、多级代理、IPv6、baseURL 子路径
 */

$root = dirname(__DIR__);
require_once $root . '/source/filebrowser_quantum/usr/local/emhttp/plugins/filebrowser_quantum/base.php';

$tmp = __DIR__ . '/tmp';
@mkdir($tmp, 0777, true);
$cfgDummy = $tmp . '/config.yaml';
$logDummy = $tmp . '/none.log';
$setDummy = $tmp . '/settings.cfg';
$missingDaemon = $tmp . '/no-daemon.sh';

$GLOBALS['CONFIG_YAML']   = $cfgDummy;
$GLOBALS['SETTINGS_FILE'] = $setDummy;
$GLOBALS['DAEMON_SCRIPT'] = $missingDaemon; // 测试环境无 Daemon.sh：走 8081 兜底
$GLOBALS['PLG_PATH']      = $tmp;

/** HTTP 段模板 */
function httpSection(array $over) {
    $defaults = [
        'port'              => '8081',
        'baseURL'           => '/',
        'internalUrl'       => '',
        'externalUrl'       => '',
        'trustProxyHeaders' => 'false',
    ];
    $cfg = array_merge($defaults, $over);
    $lines = ["http:"];
    foreach ($cfg as $k => $v) {
        $lines[] = ($v === '' || $v === null) ? "  {$k}: \"\"" : "  {$k}: \"{$v}\"";
    }
    return implode("\n", $lines) . "\n";
}

function runCase($name, array $server, $yaml, array $expect) {
    file_put_contents($GLOBALS['CONFIG_YAML'], $yaml);

    $_SERVER = array_merge(['SERVER_PORT' => '80'], $server);
    $_GET = [];

    $nav = getFileBrowserNav();
    $mixed = isMixedContentUrl($nav['url'], getRequestScheme());

    $fails = [];
    $checks = [
        'url'    => $nav['url'],
        'source' => $nav['source'],
        'isLan'  => $nav['isLan'] ? 'yes' : 'no',
        'scheme' => $nav['scheme'],
        'mixed'  => $mixed ? 'yes' : 'no',
    ];
    foreach ($expect as $k => $want) {
        $got = array_key_exists($k, $checks) ? $checks[$k] : '(unknown key)';
        if ((string)$got !== (string)$want) {
            $fails[] = "{$k}: got '{$got}', want '{$want}'";
        }
    }

    $status = $fails ? 'FAIL' : 'PASS';
    printf("[%s] %s\n", $status, $name);
    printf("        url=%s  (source=%s)\n", $nav['url'], $nav['source']);
    foreach ($fails as $f) printf("        ✗ %s\n", $f);
    return $fails ? 1 : 0;
}

$yaml = fn(array $o) => httpSection($o);

$cases = [];

// ── 用户复现场景 ────────────────────────────────────────────────────────────
$cases[] = ['A 外网 https 反代 / externalUrl 留空（原 bug：http://域名:8081）',
    ['HTTP_HOST' => 'nas.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'nas.example.com'],
    $yaml([]),
    ['url' => 'https://nas.example.com', 'source' => 'auto:request+proxy', 'isLan' => 'no', 'mixed' => 'no']];

$cases[] = ['B 外网 https 反代 / externalUrl 已配置',
    ['HTTP_HOST' => 'nas.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'nas.example.com'],
    $yaml(['externalUrl' => 'https://fb.example.com']),
    ['url' => 'https://fb.example.com', 'source' => 'config:http.externalUrl', 'mixed' => 'no']];

$cases[] = ['C 外网反代非 443 端口 / externalUrl 已配置（含端口）',
    ['HTTP_HOST' => 'nas.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'nas.example.com', 'HTTP_X_FORWARDED_PORT' => '8888'],
    $yaml(['externalUrl' => 'https://fb.example.com:8888']),
    ['url' => 'https://fb.example.com:8888', 'source' => 'config:http.externalUrl']];

$cases[] = ['D 外网反代非 443 端口 / externalUrl 留空 → 用反代端口 8443',
    ['HTTP_HOST' => 'nas.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'nas.example.com', 'HTTP_X_FORWARDED_PORT' => '8443'],
    $yaml([]),
    ['url' => 'https://nas.example.com:8443', 'source' => 'auto:request+proxy', 'mixed' => 'no']];

$cases[] = ['D2 外网 https 默认端口 443 → 不带端口',
    ['HTTP_HOST' => 'nas.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'nas.example.com', 'HTTP_X_FORWARDED_PORT' => '443'],
    $yaml([]),
    ['url' => 'https://nas.example.com']];

$cases[] = ['D3 外网 http 反代（80 端口）→ 不带端口',
    ['HTTP_HOST' => 'nas.example.com', 'HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_X_FORWARDED_HOST' => 'nas.example.com'],
    $yaml([]),
    ['url' => 'http://nas.example.com']];

// ── 内网场景 ────────────────────────────────────────────────────────────────
$cases[] = ['E 内网 http WebUI / internalUrl 留空',
    ['HTTP_HOST' => '192.168.1.10'],
    $yaml([]),
    ['url' => 'http://192.168.1.10:8081', 'source' => 'auto:request+lan', 'isLan' => 'yes', 'mixed' => 'no']];

$cases[] = ['F 内网 https WebUI（X-Forwarded-Proto=https）→ 不再产出 http iframe',
    ['HTTP_HOST' => '192.168.1.10', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    $yaml([]),
    ['url' => 'https://192.168.1.10:8081', 'mixed' => 'no']];

$cases[] = ['F2 内网 https WebUI（HTTPS=on）→ 同上',
    ['HTTP_HOST' => '192.168.1.10', 'HTTPS' => 'on', 'SERVER_PORT' => '443'],
    $yaml([]),
    ['url' => 'https://192.168.1.10:8081', 'scheme' => 'https']];

$cases[] = ['G 内网 + internalUrl 显式配置为 http，父页面 https → 提示混合内容',
    ['HTTP_HOST' => '192.168.1.10', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    $yaml(['internalUrl' => 'http://192.168.1.10:8081']),
    ['url' => 'http://192.168.1.10:8081', 'source' => 'config:http.internalUrl', 'mixed' => 'yes']];

$cases[] = ['G2 内网 + internalUrl 配置为 https → 无告警',
    ['HTTP_HOST' => '192.168.1.10', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    $yaml(['internalUrl' => 'https://192.168.1.10:8443']),
    ['url' => 'https://192.168.1.10:8443', 'mixed' => 'no']];

$cases[] = ['H 内网主机名但不带端口 / 自定义 FB 端口 9090',
    ['HTTP_HOST' => 'tower.local'],
    $yaml(['port' => '9090']),
    ['url' => 'http://tower.local:9090', 'isLan' => 'yes']];

$cases[] = ['H2 10.x 网段',
    ['HTTP_HOST' => '10.0.0.5'],
    $yaml([]),
    ['url' => 'http://10.0.0.5:8081', 'isLan' => 'yes']];

$cases[] = ['H3 172.16 网段',
    ['HTTP_HOST' => '172.16.3.7'],
    $yaml([]),
    ['url' => 'http://172.16.3.7:8081', 'isLan' => 'yes']];

$cases[] = ['H4 172.32 不属于内网 → 外网分支',
    ['HTTP_HOST' => '172.32.3.7', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    $yaml([]),
    ['url' => 'https://172.32.3.7', 'isLan' => 'no']];

// ── 回归：域名首字符 f/c/d 曾被误判为内网 ────────────────────────────────────
// 旧 isLanHost() 的正则里有未锚定的「\[?[fcd]」（本意是 IPv6 fc00::/7），
// 于是 filebrowser.002049.xyz 这类外网域名被判成内网，外网访问时错误地使用
// internalUrl（私网地址 + 混合内容拦截），或回退成「外网域名 + 内网端口」。
$cases[] = ['REG1 外网域名以 f 开头 + externalUrl/internalUrl 都已配置 → 走 externalUrl',
    ['HTTP_HOST' => 'filebrowser.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'filebrowser.example.com'],
    $yaml(['port' => '8098', 'internalUrl' => 'http://192.168.31.230:8098', 'externalUrl' => 'https://filebrowser.example.com:8888']),
    ['url' => 'https://filebrowser.example.com:8888', 'source' => 'config:http.externalUrl', 'isLan' => 'no', 'mixed' => 'no']];

$cases[] = ['REG2 外网域名以 f 开头 + externalUrl 留空 → 按反代头推导，不得落到内网端口',
    ['HTTP_HOST' => 'filebrowser.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'filebrowser.example.com'],
    $yaml(['port' => '8098']),
    ['url' => 'https://filebrowser.example.com', 'source' => 'auto:request+proxy', 'isLan' => 'no', 'mixed' => 'no']];

$cases[] = ['REG3 外网域名以 c 开头（cloud.example.com）',
    ['HTTP_HOST' => 'cloud.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    $yaml([]),
    ['url' => 'https://cloud.example.com', 'isLan' => 'no']];

$cases[] = ['REG4 外网域名以 d 开头（dns.example.com）',
    ['HTTP_HOST' => 'dns.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    $yaml([]),
    ['url' => 'https://dns.example.com', 'isLan' => 'no']];

$cases[] = ['REG5 以 10. 开头的域名（10.example.com）不是内网',
    ['HTTP_HOST' => '10.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    $yaml([]),
    ['url' => 'https://10.example.com', 'isLan' => 'no']];

$cases[] = ['REG6 IPv6 ULA fd00::/8 仍判为内网',
    ['HTTP_HOST' => '[fd00::1]:8098'],
    $yaml([]),
    ['url' => 'http://[fd00::1]:8081', 'isLan' => 'yes']];

$cases[] = ['REG7 IPv6 链路本地 fe80:: 仍判为内网',
    ['HTTP_HOST' => '[fe80::1]'],
    $yaml([]),
    ['url' => 'http://[fe80::1]:8081', 'isLan' => 'yes']];

$cases[] = ['REG8 IPv6 回环 ::1 仍判为内网',
    ['HTTP_HOST' => '[::1]:8081'],
    $yaml([]),
    ['url' => 'http://[::1]:8081', 'isLan' => 'yes']];

$cases[] = ['REG9 反代头为内网 IP、HTTP_HOST 为外网域名 → 内网分支用 internalUrl',
    ['HTTP_HOST' => 'filebrowser.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => '192.168.31.230:8098'],
    $yaml(['port' => '8098', 'internalUrl' => 'http://192.168.31.230:8098', 'externalUrl' => 'https://filebrowser.example.com:8888']),
    ['url' => 'http://192.168.31.230:8098', 'source' => 'config:http.internalUrl', 'isLan' => 'yes']];

// ── 反代细节 ────────────────────────────────────────────────────────────────
$cases[] = ['I 外网反代 / X-Forwarded-Host 与 HTTP_HOST 不同（nginx 常见写法）',
    ['HTTP_HOST' => '192.168.1.10:8081', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'fb.example.com'],
    $yaml([]),
    ['url' => 'https://fb.example.com', 'isLan' => 'no']];

$cases[] = ['J 多级代理 / 逗号分隔的首段生效',
    ['HTTP_HOST' => 'internal:80', 'HTTP_X_FORWARDED_PROTO' => 'https, http', 'HTTP_X_FORWARDED_HOST' => 'fb.example.com, edge.local', 'HTTP_X_FORWARDED_PORT' => '443'],
    $yaml([]),
    ['url' => 'https://fb.example.com', 'scheme' => 'https']];

$cases[] = ['K baseURL 子路径自动补全',
    ['HTTP_HOST' => 'nas.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    $yaml(['baseURL' => '/files/']),
    ['url' => 'https://nas.example.com/files/']];

$cases[] = ['K2 baseURL 已在 URL 中则不重复拼接',
    ['HTTP_HOST' => 'nas.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    $yaml(['baseURL' => '/files', 'externalUrl' => 'https://fb.example.com/files']),
    ['url' => 'https://fb.example.com/files']];

$cases[] = ['L IPv6 内网地址',
    ['HTTP_HOST' => '[fd00::1]:8081'],
    $yaml([]),
    ['url' => 'http://[fd00::1]:8081', 'isLan' => 'yes']];

$cases[] = ['L2 IPv6 外网 + 反代头',
    ['HTTP_HOST' => '[fd00::1]:8081', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'fb.example.com'],
    $yaml([]),
    ['url' => 'https://fb.example.com', 'isLan' => 'no']];

$cases[] = ['M 端口出现在 Host 头（外网 :9443）',
    ['HTTP_HOST' => 'fb.example.com:9443', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    $yaml([]),
    ['url' => 'https://fb.example.com:9443']];

$cases[] = ['N 显式 internalUrl 优先于推导（内网访问）',
    ['HTTP_HOST' => '192.168.1.10'],
    $yaml(['internalUrl' => 'http://192.168.1.10:9999']),
    ['url' => 'http://192.168.1.10:9999', 'source' => 'config:http.internalUrl']];

$cases[] = ['O 外网访问时 internalUrl 不生效（避免把内网地址发到公网）',
    ['HTTP_HOST' => 'fb.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    $yaml(['internalUrl' => 'http://192.168.1.10:8081']),
    ['url' => 'https://fb.example.com', 'source' => 'auto:request+proxy']];

// ── 配置解析健壮性 ──────────────────────────────────────────────────────────
$cases[] = ['P 注释行尾 / 键名跨段不串味（server.logging.output 不应被当成 http 键）',
    ['HTTP_HOST' => '192.168.1.10'],
    "http:\n  port: 8081 # 监听端口\n  baseURL: \"/\"\nserver:\n  port: 9999\n  logging:\n    - output: \"/var/log/x.log\"\n",
    ['url' => 'http://192.168.1.10:8081']];

$cases[] = ['Q http.port 缺失 → 回退 8081',
    ['HTTP_HOST' => '192.168.1.10'],
    "http:\n  baseURL: \"/\"\nserver:\n  port: 9999\n",
    ['url' => 'http://192.168.1.10:8081']];

$cases[] = ['R 单引号值也能解析',
    ['HTTP_HOST' => 'nas.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    "http:\n  port: '8082'\n  externalUrl: 'https://fb.example.com'\n",
    ['url' => 'https://fb.example.com']];

$cases[] = ['S config.yaml 不存在 → 全默认值（不报错）',
    ['HTTP_HOST' => 'nas.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    null,
    ['url' => 'https://nas.example.com', 'source' => 'auto:request+proxy']];

$fail = 0;
$total = 0;
foreach ($cases as $c) {
    $total++;
    $isMissing = ($c[2] === null);   // 模拟 config.yaml 不存在
    // 每个用例都必须把 CONFIG_YAML 指回真实文件，否则会被上一个用例的路径带跑
    $GLOBALS['CONFIG_YAML'] = $isMissing ? $tmp . '/definitely-missing.yaml' : $cfgDummy;
    $fail += runCase($c[0], $c[1], (string)$c[2], $c[3]);
}
$GLOBALS['CONFIG_YAML'] = $cfgDummy;

// ── 联动检查：设置页 OPEN WEBUI 按钮必须使用后端算好的地址 ────────────────────
// 旧 bug：前端自己拼 'http://' + window.location.hostname + ':' + 内网端口，
// 外网访问时得到「外网域名 + 内网端口」，并被混合内容策略拦截。
$plgDir  = realpath(__DIR__ . '/../source/filebrowser_quantum/usr/local/emhttp/plugins/filebrowser_quantum');
$apiSrc  = (string)@file_get_contents($plgDir . '/api_config.php');
$pageSrc = (string)@file_get_contents($plgDir . '/filebrowser_quantum.page');

foreach ([
    'api_config.php 在 get_status 中返回 nav_url'          => strpos($apiSrc, "'nav_url' => \$nav['url']") !== false,
    'api_config.php 调用 getFileBrowserNav()'              => strpos($apiSrc, 'getFileBrowserNav()') !== false,
    '设置页不再用 window.location.hostname 拼地址'          => strpos($pageSrc, 'window.location.hostname') === false,
    '设置页把 nav_url 传给 updateServiceUI（3 处调用点）'   => substr_count($pageSrc, 'data.nav_url') === 3,
    '设置页没有漏掉 nav_url 的 get_status 调用点'           => substr_count($pageSrc, 'updateServiceUI(data.running, data.port);') === 0,
    'FB 标签页与设置页共用 getFileBrowserNav()'             => strpos((string)@file_get_contents($plgDir . '/FB.page'), 'getFileBrowserNav()') !== false,
] as $label => $ok) {
    $total++;
    if ($ok) {
        printf("[PASS] %s\n", $label);
    } else {
        $fail++;
        printf("[FAIL] %s\n", $label);
    }
}

echo "\n==== {$total} cases, " . ($total - $fail) . " passed, {$fail} failed ====\n";
exit($fail > 0 ? 1 : 0);

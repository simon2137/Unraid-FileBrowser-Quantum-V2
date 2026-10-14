<?php
/**
 * FB.page 整页渲染测试：把 .page 当模板跑一遍，检查最终 iframe 地址与告警条
 *   php .diag/fb_page_render_test.php
 *
 * 原理：base.php 顶部会从 Unraid 绝对路径读取 paths.conf，
 *      测试时临时把该文件指向 .diag/tmp 下的假路径，跑完再还原。
 */

$root = dirname(__DIR__);
$srcPage = $root . '/source/filebrowser_quantum/usr/local/emhttp/plugins/filebrowser_quantum/FB.page';
$basePhp = $root . '/source/filebrowser_quantum/usr/local/emhttp/plugins/filebrowser_quantum/base.php';
$tmp = __DIR__ . '/tmp';
@mkdir($tmp, 0777, true);

$pathsConf = '/usr/local/emhttp/plugins/filebrowser_quantum/paths.conf';
$pathsConfBak = null;
if (file_exists($pathsConf)) {
    $pathsConfBak = file_get_contents($pathsConf);
    $dir = dirname($pathsConf);
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
}

function writePathsConf($confPath, array $paths) {
    $lines = [];
    foreach ($paths as $k => $v) $lines[] = "$k=\"$v\"";
    @file_put_contents($confPath, implode("\n", $lines) . "\n");
}

$render = function ($scenario, array $server, $yaml, array $query = []) use ($srcPage, $basePhp, $tmp, $pathsConf) {
    $cfg = $tmp . '/' . $scenario . '.yaml';
    file_put_contents($cfg, $yaml);
    writePathsConf($pathsConf, [
        'DAEMON_SCRIPT' => $tmp . '/no-daemon.sh',
        'BINARY'        => $tmp . '/no-binary',
        'PLG_PATH'      => $tmp,
        'CONFIG_YAML'   => $cfg,
        'SETTINGS_FILE' => $tmp . '/settings.cfg',
        'INSTALL_PATH'  => $tmp . '/install',
    ]);

    $_SERVER = array_merge(['SERVER_PORT' => '80'], $server);
    $_GET = $query;

    // base.php 是 require_once 引入的，其内部赋值的路径变量不会自动同步到全局作用域，
    // 因此这里显式把当前场景的配置路径写回全局
    $GLOBALS['CONFIG_YAML']   = $cfg;
    $GLOBALS['SETTINGS_FILE'] = $tmp . '/settings.cfg';
    $GLOBALS['DAEMON_SCRIPT'] = $tmp . '/no-daemon.sh';

    // 把 .page 的绝对 include 换成仓库内真实路径，其余原样执行
    $code = file_get_contents($srcPage);
    $code = str_replace(
        'include "/usr/local/emhttp/plugins/filebrowser_quantum/base.php";',
        'require_once ' . var_export($basePhp, true) . ';',
        $code
    );
    // 去掉 .page 文件头（--- 之前的元数据）
    $code = preg_replace('/^.*?^---\R/ms', '', $code);

    ob_start();
    eval('?>' . $code);
    return ob_get_clean();
};

function assertContains($html, $needle, $label) {
    $ok = strpos($html, $needle) !== false;
    printf("    %s %s\n", $ok ? '✓' : '✗', $label);
    if (!$ok) printf("        missing: %s\n", $needle);
    return $ok ? 0 : 1;
}

function extractIframe($html) {
    if (preg_match('/<iframe src="([^"]+)"/', $html, $m)) return html_entity_decode($m[1], ENT_QUOTES);
    return '(no iframe found)';
}

$fail = 0;
$total = 0;
$yamlOf = function (array $o) {
    $cfg = array_merge(['port' => '8081', 'baseURL' => '/', 'internalUrl' => '', 'externalUrl' => '', 'trustProxyHeaders' => 'false'], $o);
    $lines = ['http:'];
    foreach ($cfg as $k => $v) $lines[] = ($v === '') ? "  {$k}: \"\"" : "  {$k}: \"{$v}\"";
    return implode("\n", $lines) . "\n";
};

// ── 场景 1：用户报告的场景（https 域名反代 + externalUrl 留空） ─────────────
$total++;
echo "[1] 外网 https 反代，externalUrl 留空（原 bug）\n";
$html = $render('s1',
    ['HTTP_HOST' => 'nas.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'nas.example.com'],
    $yamlOf([]));
$iframe = extractIframe($html);
printf("    iframe = %s\n", $iframe);
$fail += assertContains($iframe, 'https://nas.example.com?v=', 'iframe 指向 https 反代域名（不再带 :8081、不再是 http）');
$fail += assertContains($html, 'fb-full-iframe', 'iframe 原样式保留');
$fail += assertContains($html, 'top: 130px', 'PC 布局高度未变');

// ── 场景 2：https 反代 + externalUrl 已配置 ────────────────────────────────
$total++;
echo "[2] 外网 https 反代，externalUrl 已配置\n";
$html = $render('s2',
    ['HTTP_HOST' => 'nas.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'nas.example.com'],
    $yamlOf(['externalUrl' => 'https://fb.example.com']));
$iframe = extractIframe($html);
printf("    iframe = %s\n", $iframe);
$fail += assertContains($iframe, 'https://fb.example.com?v=', '优先使用配置的 externalUrl');
if (strpos($html, 'id="fb-warn"') !== false) { printf("    ✗ 误报：不该出现告警条\n"); $fail++; }
else printf("    ✓ 无告警条\n");

// ── 场景 3：内网 http 直连 ─────────────────────────────────────────────────
$total++;
echo "[3] 内网 http 直连\n";
$html = $render('s3', ['HTTP_HOST' => '192.168.1.10'], $yamlOf([]));
$iframe = extractIframe($html);
printf("    iframe = %s\n", $iframe);
$fail += assertContains($iframe, 'http://192.168.1.10:8081?v=', '内网直连走 FB 端口');
if (strpos($html, 'id="fb-warn"') !== false) { printf("    ✗ 误报：http 页面出现告警条\n"); $fail++; }

// ── 场景 4：内网 + internalUrl 显式 http，父页面 https → 告警条 ────────────
$total++;
echo "[4] https 页面 + http internalUrl → 混合内容告警\n";
$html = $render('s4',
    ['HTTP_HOST' => '192.168.1.10', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    $yamlOf(['internalUrl' => 'http://192.168.1.10:8081']));
$iframe = extractIframe($html);
printf("    iframe = %s\n", $iframe);
$fail += assertContains($html, 'id="fb-warn"', '出现混合内容告警条');
$fail += assertContains($html, '混合内容被浏览器拦截', '告警文案存在');
$fail += assertContains($iframe, 'http://192.168.1.10:8081?v=', '仍保留用户显式配置的 internalUrl');

// ── 场景 5：?fbdebug=1 调试面板 ────────────────────────────────────────────
$total++;
echo "[5] ?fbdebug=1 调试面板\n";
$html = $render('s5', ['HTTP_HOST' => 'nas.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https'], $yamlOf([]), ['fbdebug' => '1']);
$fail += assertContains($html, 'class="fb-debug"', '调试面板渲染');
$fail += assertContains($html, 'source: auto:request+proxy', '显示来源');
if (strpos($html, 'fb-debug') === false) { printf("    ✗ 调试面板未渲染\n"); }

// ── 场景 6：不带 ?fbdebug 时无调试面板 ─────────────────────────────────────
$total++;
echo "[6] 默认不显示调试面板\n";
$html = $render('s6', ['HTTP_HOST' => 'nas.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https'], $yamlOf([]));
if (strpos($html, 'class="fb-debug"') !== false) { printf("    ✗ 默认渲染了调试面板\n"); $fail++; }
else printf("    ✓ 无调试面板\n");

// ── 场景 7：无 X-Forwarded-* 的纯 http 反代（常见 nginx 忘配头） ───────────
$total++;
echo "[7] 反代未传 X-Forwarded-Proto（只能看到 HTTP_HOST）\n";
$html = $render('s7', ['HTTP_HOST' => 'fb.example.com'], $yamlOf([]));
$iframe = extractIframe($html);
printf("    iframe = %s\n", $iframe);
$fail += assertContains($iframe, 'http://fb.example.com?v=', '退化为 http://反代域名（端口正确，不再是 :8081）');

// 还原真实 paths.conf
if ($pathsConfBak !== null) file_put_contents($pathsConf, $pathsConfBak);
else @unlink($pathsConf);

echo "\n==== {$total} render scenarios, " . ($fail === 0 ? 'ALL PASS' : "{$fail} assertion(s) failed") . " ====\n";
exit($fail > 0 ? 1 : 0);

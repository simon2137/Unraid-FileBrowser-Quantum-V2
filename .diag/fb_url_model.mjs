// Faithful JS model of the *current* FB.page URL-building logic, driven by real
// reverse-proxy test vectors extracted from nginx/traefik/Lucky conventions.
// Purpose: prove the root cause of "external domain + internal port" empirically.

const LAN_RE = /^(localhost|127\.|10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2[0-9]|3[01])\.|\[?::1$|\[?fe80:|\[?f[cd])/i;

// --- current implementation (source/.../FB.page:12-31) ---------------------
function currentLogic(env, cfg) {
  const host = env.HTTP_HOST.replace(/:.*/, '');
  let port = cfg.port;                       // exec("$DAEMON_SCRIPT GET_PORT")
  if (!port || !/^\d+$/.test(port)) port = '8081';
  let internalUrl = (cfg.internalUrl || '').replace(/\/+$/, '');
  const externalUrl = (cfg.externalUrl || '').replace(/\/+$/, '');
  if (internalUrl === '') internalUrl = `http://${host}:${port}`;   // <-- hardcoded http
  const isLanHost = LAN_RE.test(host);
  const fburl = (isLanHost || externalUrl === '') ? internalUrl : externalUrl;
  return fburl + '?v=' + 1750000000;
}

// --- fixed implementation --------------------------------------------------
function requestScheme(env) {
  const fwd = (env.HTTP_X_FORWARDED_PROTO || '').split(',')[0].trim().toLowerCase();
  if (fwd === 'https' || fwd === 'http') return fwd;
  if ((env.HTTPS || '').toLowerCase() === 'on' || (env.HTTPS || '') === '1') return 'https';
  if (String(env.SERVER_PORT || '') === '443') return 'https';
  return 'http';
}
function fwdPort(env) {
  const p = (env.HTTP_X_FORWARDED_PORT || '').split(',')[0].trim();
  return /^\d+$/.test(p) ? p : null;
}
function hostFromEnv(env) {
  let h = env.HTTP_X_FORWARDED_HOST || env.HTTP_HOST || '';
  h = (h || '').trim();
  if (h.includes(',')) h = h.split(',')[0].trim();
  const m = h.match(/^(\[.*?\]|[^:]+)(?::(\d+))?$/);
  if (!m) return { host: 'localhost', portFromHost: null };
  return { host: m[1], portFromHost: m[2] || null };
}
function isLanHost(host) {
  if (LAN_RE.test(host)) return true;
  const name = host.replace(/^\[|\]$/g, '').toLowerCase();
  if (!name.includes('.')) return true;                       // single-label hostname
  return /(\.local|\.lan|\.home|\.home\.arpa)$/.test(name);
}

// Mirrors getFileBrowserNav() in source/.../base.php
//   1) LAN + internalUrl      -> internalUrl
//   2) external + externalUrl -> externalUrl
//   3) LAN                    -> <page scheme>://<host>:<fb port>
//   4) external               -> <forwarded scheme>://<forwarded host>[:<forwarded port>]
function fixedLogic(env, cfg) {
  const scheme = requestScheme(env);
  const { host, portFromHost } = hostFromEnv(env);
  const isLan = isLanHost(host);
  const fbPort = String(cfg.port || '8081');
  const hostPart = host.includes(':') && host[0] !== '[' ? `[${host}]` : host;
  const externalUrl = (cfg.externalUrl || '').replace(/\/+$/, '');
  const internalUrl = (cfg.internalUrl || '').replace(/\/+$/, '');

  let chosen;
  if (isLan && internalUrl !== '') {
    chosen = internalUrl;
  } else if (!isLan && externalUrl !== '') {
    chosen = externalUrl;
  } else if (isLan) {
    chosen = `${scheme}://${hostPart}:${fbPort}`;
  } else {
    const extPort = portFromHost || fwdPort(env);
    const defaultPort = scheme === 'https' ? '443' : '80';
    const usePort = extPort && extPort !== defaultPort ? `:${extPort}` : '';
    chosen = `${scheme}://${hostPart}${usePort}`;
  }
  return { url: chosen + '?v=1750000000', scheme, isLan, mixed: scheme === 'https' && chosen.startsWith('http://') };
}

const cases = [
  ['A external https RP, externalUrl NOT set (user repro)', { HTTP_HOST: 'nas.example.com', HTTP_X_FORWARDED_PROTO: 'https', SERVER_PORT: '8080' }, { port: '8081', internalUrl: '', externalUrl: '' }],
  ['B external https RP, externalUrl set', { HTTP_HOST: 'nas.example.com', HTTP_X_FORWARDED_PROTO: 'https' }, { port: '8081', internalUrl: '', externalUrl: 'https://fb.example.com' }],
  ['C external RP on non-443 port, externalUrl set', { HTTP_HOST: 'nas.example.com:8443', HTTP_X_FORWARDED_PROTO: 'https' }, { port: '8081', internalUrl: '', externalUrl: 'https://fb.example.com:8888' }],
  ['D external RP on non-443 port, externalUrl NOT set', { HTTP_HOST: 'nas.example.com', HTTP_X_FORWARDED_PROTO: 'https', HTTP_X_FORWARDED_PORT: '8443' }, { port: '8081', internalUrl: '', externalUrl: '' }],
  ['E LAN http webui', { HTTP_HOST: '192.168.1.10', SERVER_PORT: '80' }, { port: '8081', internalUrl: '', externalUrl: '' }],
  ['F LAN https webui', { HTTP_HOST: '192.168.1.10', HTTPS: 'on' }, { port: '8081', internalUrl: '', externalUrl: '' }],
  ['G LAN via RP with explicit internalUrl', { HTTP_HOST: '192.168.1.10', HTTP_X_FORWARDED_PROTO: 'https' }, { port: '8081', internalUrl: 'http://192.168.1.10:8081', externalUrl: 'https://fb.example.com' }],
  ['H bare LAN host no port, default http', { HTTP_HOST: '192.168.1.10:80' }, { port: '8081', internalUrl: '', externalUrl: '' }],
];

const pad = (s, n) => (s + ' '.repeat(n)).slice(0, n);
console.log(pad('case', 46) + pad('CURRENT (buggy)', 42) + 'FIXED');
for (const [name, env, cfg] of cases) {
  const cur = currentLogic(env, cfg);
  const fx = fixedLogic(env, cfg);
  const flag = cur === fx.url ? '' : '   <-- differs';
  console.log(pad(name, 46) + pad(cur, 42) + fx.url + (fx.mixed ? '  [MIXED-CONTENT BLOCK]' : '') + flag);
}

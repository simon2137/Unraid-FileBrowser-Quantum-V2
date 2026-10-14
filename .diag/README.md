# .diag —— 开发期诊断与验证脚本（不进入插件功能包）

这些文件只用于在开发机上复现/验证 FB 标签页的内外网分流逻辑，不会被打进
`archive/filebrowser_quantum-*.txz`（打包只取 `source/filebrowser_quantum/` 下的文件）。

## 文件说明

| 文件 | 作用 |
|---|---|
| `fb_url_test.php` | base.php 的 `getFileBrowserNav()` 单元测试：28 组场景（外网 https 反代、反代非 443 端口、多级代理、IPv6、内网 https WebUI、baseURL 子路径、config.yaml 分段解析等） |
| `fb_page_render_test.php` | FB.page 整页渲染测试：把 .page 当模板执行，检查最终 iframe 地址、混合内容告警条、`?fbdebug=1` 调试面板 |
| `fb_url_model.mjs` | 纯 Node 版对照模型（无需 PHP），用于对比「修复前 vs 修复后」的 URL 结果 |
| `build_pkg.py` | 在 Windows 上重新打包 `archive/*.txz`（补上 bsdtar 丢失的 0755/0644 权限位，产物字节可复现） |
| `tmp/` | 测试运行时生成的临时文件，可随时删除 |

## 运行方式

```bash
# 需要 PHP 8.x（Windows 可用便携版；Unraid 自带 PHP 亦可）
php .diag/fb_url_test.php
php .diag/fb_page_render_test.php

# 纯 Node 对照模型
node .diag/fb_url_model.mjs
```

两个 PHP 测试脚本都会在结尾打印 `N cases, M passed`，失败时以非 0 退出。
`fb_page_render_test.php` 会在 `tmp/` 与 `/usr/local/emhttp/plugins/filebrowser_quantum/paths.conf`
之间临时改写路径，跑完自动还原（Linux/macOS 以 root 运行时才会真正写入该绝对路径，
普通用户权限不足时会静默跳过）。

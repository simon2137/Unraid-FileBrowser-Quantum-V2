# FileBrowser Quantum 插件 (UNRAID)

<p align="center">
  <img src="https://raw.githubusercontent.com/insomnia417/Unraid-FileBrowser_Quantum-Plugins/master/img/logo.png" width="128" height="128" alt="Logo">
</p>

<p align="center">
  <a href="README.md">English</a> | 中文
</p>

<p align="center">
  <img src="img/FB.png" width="100%" alt="Logo">
  <img src="img/image.png" width="100%" alt="Logo">
</p>

---

## 简介

**FileBrowser Quantum** 是一款专为 Unraid 打造的高性能文件管理器插件，基于 [FileBrowser Quantum](https://github.com/gtsteffaniak/filebrowser)（原 FileBrowser 的活跃维护分支）v2 内核，为 Unraid 提供现代、流畅的文件管理体验。

### 特性

- **Quantum v2 内核**：SQLite 数据库、索引加速搜索、图片/视频/办公文档预览、分享链接、WebDAV、多源管理、API Token 等，详见[上游文档](https://filebrowserquantum.com/en/docs/)
- **内外网分流**：FQ 标签页按访问方式自动切换 FileBrowser 地址，反向代理下也能正常工作
- **图形化管理**：设置页可视化编辑 `config.yaml`，保存前经二进制真实校验
- **一键更新**：插件内检查并安装上游新版本，支持 stable / beta 分支切换
- **实时日志**：设置页内直接查看服务日志，无需登录终端

---

## 安装方法

在 Unraid **插件 (Plugins)** 标签页，点击 **安装插件**，在输入框中输入以下 URL：
```
https://raw.githubusercontent.com/insomnia417/Unraid-FileBrowser_Quantum-Plugins/refs/heads/main/FileBrowser_Quantum.plg
```

---

## 初始登录

| 场景 | 登录方式 |
|---|---|
| **全新安装** | 用户名 `admin`，密码为**随机生成的初始密码**，只在首次启动时打印一次到日志，见下方"如何找到初始密码" |
| **从 v1 升级** | 原账号与密码经数据库迁移完整保留，用原密码登录 |

**忘记密码 / 没找到初始密码**，在 Unraid 终端执行：

```bash
filebrowser_quantum user set admin --password '你的新密码' --admin
```

**查看初始密码**（全新安装后的第一次启动期间）：

```bash
grep -i "password" /var/log/filebrowser_quantum.log | head -5
```

> 注意：插件设置页的"实时日志"只显示最后 50 行，启动早期的信息请在终端查看完整日志文件。

---

## 内外网分流（反向代理）

FQ 标签页会根据你访问 Unraid WebUI 的方式，自动选择 FileBrowser 的加载地址。在 `config.yaml` 的 `http:` 节点下配置：

```yaml
http:
  port: 8081
  baseURL: "/"
  # 内网直连地址：通过内网 IP 访问 FQ 标签页时使用；留空 = 自动使用 http://<访问主机>:<端口>
  internalUrl: ""
  # 外网反代地址：通过域名访问时使用，例如 https://filebrowser.example.com:8888
  externalUrl: ""
```

| 访问 Unraid 的方式 | FQ 标签页加载 |
|---|---|
| 内网 IP，如 `http://192.168.1.10/` | `internalUrl`（留空时为 `http://192.168.1.10:8081`） |
| 域名，如 `https://nas.example.com/` | `externalUrl` |

- `externalUrl` 必须走 **HTTPS**（页面是 https 时，浏览器会拦截 http 的 iframe，即"混合内容"）
- 判定规则：访问主机是内网 IP 段（127/10/172.16-31/192.168/169.254、localhost）视为内网，其余一律走 `externalUrl`；未配置 `externalUrl` 时始终回退内网行为

### FileBrowser 反代配置要求

`externalUrl` 指向的反向代理（目标 `http://<NAS_IP>:8081`）需要：

```nginx
location / {
    proxy_pass http://192.168.1.10:8081;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_buffering off;        # Quantum 实时功能依赖 SSE，必须关闭缓冲
    client_max_body_size 0;     # 不限制上传大小
}
```

- **Nginx Proxy Manager**：开启 **Websockets Support** 即可覆盖升级头
- **Lucky**：确认已开启 WebSocket/SSE 透传
- 反代本身可用性验证：浏览器直接打开 `externalUrl`，应能看到 FileBrowser 登录页

---

## 从 v1 升级（自动迁移）

安装本插件（或更新到）v2 版本时，升级流程**全自动**完成，无需手动干预：

1. **配置转换**：检测到 v1 版 `config.yaml` 后自动转为 v2 结构，**保留端口、`internalUrl` / `externalUrl`**；旧文件备份为 `config.yaml.v1.bak`
2. **数据库迁移**：旧 BoltDB（`database.db`）自动更名为 `database.db.old`，v2 首次启动时通过 `migrateFrom` 自动把**用户、密码、分享链接、API Token** 导入 SQLite（`filebrowser.sqlite`），完成后自动移除一次性迁移标记
3. **缓存清理**：v1 主程序缓存自动丢弃，重新下载 v2 主程序

升级后请注意：

- **权限模型变化**：v2 将 v1 的全局 modify/download 权限改为按目录（scope）授权，迁移后请到 FileBrowser 设置页检查各用户的目录权限
- 建议升级前手动备份一次数据库：`cp /boot/config/plugins/filebrowser_quantum/database.db ~/database.db.backup`
- 迁移失败想回滚：装回旧版插件，将 `database.db.old` 改回 `database.db`，并用 `config.yaml.v1.bak` 覆盖 `config.yaml`

---

## config.yaml 参考（v2）

```yaml
http:
  port: 8081
  baseURL: "/"
  internalUrl: ""            # 内网直连地址（FQ 标签页）
  externalUrl: ""            # 外网反代地址（FQ 标签页）
  trustProxyHeaders: false   # 位于反代之后建议设为 true
server:
  database:
    path: "/boot/config/plugins/filebrowser_quantum/filebrowser.sqlite"
  logging:
    - levels: "info|debug|warning|error"
      output: "/var/log/filebrowser_quantum.log"
  sources:
    - path: "/"
      name: "Root Dir"
      config:
        rules:               # 索引排除规则（隐藏系统目录）
          - folderPath: "/proc"
          - folderPath: "/sys"
          - folderPath: "/dev"
auth:
  methods:
    password:
      enabled: true
```

完整字段说明见[上游配置文档](https://filebrowserquantum.com/en/docs/configuration/)。

---

## 常见问题

**FQ 标签页空白/一直转圈？**
按 F12 看 iframe 的 src：域名访问时它应该是 `externalUrl`。若 src 是 `https://域名:内部端口`，说明 `externalUrl` 未配置；若浏览器报"混合内容"，说明 `externalUrl` 是 http。

**提示密码错误？**
先确认没触发防爆破（连续失败会临时封 IP，等 10 分钟）；确认后仍失败用本文"初始登录"一节的命令重置。

**实时日志里看不到启动/迁移信息？**
日志窗口只显示最后 50 行，完整日志在 `/var/log/filebrowser_quantum.log`，用终端 `grep` 查看。

**插件文件在重启后改动丢失？**
`/usr/local/emhttp` 位于内存中，属正常现象。插件功能包会随启动自动重载；如需自行修改插件文件，请修改 U 盘 `/boot/config/plugins/filebrowser_quantum/install/` 内的 txz 或使用 User Scripts 插件在开机时打补丁。

---

## 关于

2025-2026 元旦跨年期间折腾 NAS 的产物。

## 鸣谢

原项目：[FileBrowser Quantum](https://github.com/gtsteffaniak/filebrowser)

参考项目：[rclone-unraid](https://github.com/ich777/rclone-unraid)

特别感谢： InSnocent。他是我的 GitHub 启蒙老师，这也是我第一个正式项目，也许也是最后一个（笑）。

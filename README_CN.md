# FileBrowser Quantum 插件(UNRAID)

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

**FileBrowser Quantum** 是一款专为 Unraid 打造的高性能文件管理器插件。它基于 FileBrowser 核心，通过前后端分离架构进行了深度重构，为 Unraid 用户提供更加现代、流畅的文件管理体验。

---

## 安装方法

### 方法 1：通过应用市场 (推荐)
1. 打开 Unraid 控制台，点击 **Apps** 标签页。
2. 搜索 `FileBrowser Quantum`。
3. 点击 **Install**。

### 方法 2：手动安装
在 Unraid **插件 (Plugins)** 标签页，点击 **安装插件**，在输入框中输入以下 URL：
```
https://raw.githubusercontent.com/insomnia417/Unraid-FileBrowser_Quantum-Plugins/refs/heads/main/FileBrowser_Quantum.plg
````
## 配置说明
安装完成后，您前往以下路径进行插件的详细配置： Settings (设置) -> Utilities (实用工具) -> FileBrowser Quantum

### 默认登录信息
默认账号： admin
默认密码： admin

### 内外网分流（反向代理）
FB 标签页会根据访问方式自动选择 FileBrowser 地址，在 `config.yaml` 的 `http:` 节点下配置：

```yaml
http:
  # 内网直连地址：通过内网 IP 访问 FB 标签页时使用；留空 = 自动使用 http://<访问主机>:<端口>
  internalUrl: ""
  # 外网反代地址：通过域名/公网访问时使用，如 https://filebrowser.example.com:8888
  externalUrl: ""
```

- 通过内网 IP（如 `http://192.168.1.10/`）访问 Unraid 时，iframe 走 `internalUrl`；
- 通过域名（如 `https://nas.example.com/`）访问时，iframe 走 `externalUrl`（需配置 HTTPS 反代，否则浏览器会拦截混合内容）。

### FileBrowser Quantum v2
- 插件已适配上游 v2（默认安装 v2.0.1-stable；beta 分支为 v2.1.x）。v2 将数据库从 BoltDB 换成 SQLite、配置结构调整（`port/baseURL/internalUrl/externalUrl` 移至 `http:` 段）。
- **从旧版升级**：安装时自动转换 v1 配置（保留端口与内外网地址），并把旧 `database.db` 自动迁移到 SQLite；旧文件备份为 `database.db.old` 与 `config.yaml.v1.bak`。
- v2 的密码重置命令变为：`filebrowser_quantum user set admin --password '新密码'`（v1 是 `users update`）。

## 关于
2025-2026 元旦跨年期间折腾 NAS 的产物。

## 鸣谢
原项目： [FileBrowser Quantum](https://github.com/gtsteffaniak/filebrowser)

参考项目：[rclone-unraid](https://github.com/ich777/rclone-unraid)

特别感谢： InSnocent。他是我的 GitHub 启蒙老师，这也是我第一个正式项目，也许也是最后一个（笑）。

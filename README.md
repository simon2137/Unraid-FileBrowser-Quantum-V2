# FileBrowser Quantum for Unraid

<p align="center">
  <img src="https://raw.githubusercontent.com/insomnia417/Unraid-FileBrowser_Quantum-Plugins/master/img/logo.png" width="128" height="128" alt="Logo">
</p>

<p align="center">
  <a href="README_CN.md">中文</a> | English
</p>

<p align="center">
  <img src="img/FB.png" width="100%" alt="Logo">
  <img src="img/image.png" width="100%" alt="Logo">
</p>

---

## Introduction

**FileBrowser Quantum** is a high-performance file manager plugin designed specifically for Unraid. Based on the FileBrowser core, it features a refactored decoupled architecture to provide a modern and smooth file management experience.

---
### Installation
1. Open Unraid console, click the **Apps** tab.
2. Search for `FileBrowser Quantum` and click **Install**.
3. *Alternatively*, install manually via URL in **Plugins** -> **Install Plugin**:
```
https://raw.githubusercontent.com/insomnia417/Unraid-FileBrowser_Quantum-Plugins/main/FileBrowser_Quantum.plg
```

Open unRAID WebUI, go to the "Plugins" tab, click "Install Plugin", and enter the following URL in the input box:



---

## Configuration

After installation, go to **Settings -> Utilities -> FileBrowser Quantum** to configure the plugin.

###
+ Default account: admin<br/>

+ Default password: admin<br/>

+ For more information, please refer to the [FileBrowser Quantum official documentation](https://filebrowserquantum.com/en/docs/)<br/>

### Internal/External URL split (reverse proxy)

The FB tab picks the FileBrowser URL automatically based on how you access the Unraid WebUI. Configure both under the `server` node of `config.yaml`:

```yaml
server:
  # Internal URL used when accessing via LAN IP; empty = auto http://<host>:<port>
  internalUrl: ""
  # External URL used when accessing via domain, e.g. https://filebrowser.example.com:8888
  externalUrl: ""
```

- Accessing Unraid by LAN IP (e.g. `http://192.168.1.10/`) loads FileBrowser via `internalUrl`.
- Accessing by domain (e.g. `https://nas.example.com/`) loads it via `externalUrl` (an HTTPS reverse proxy is required, otherwise the browser blocks mixed content).

## THANKS
[FileBrowser Quantum](https://github.com/gtsteffaniak/filebrowser)

[rclone-unraid](https://github.com/ich777/rclone-unraid)

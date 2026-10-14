#!/bin/bash
# 引入全局变量路径
source /usr/local/emhttp/plugins/filebrowser_quantum/paths.conf

# === 辅助函数：读取 settings.cfg 的值 ===
get_setting() {
    local key="$1"
    local default="$2"
    if [ -f "$SETTINGS_FILE" ]; then
        local val=$(grep "^${key}=" "$SETTINGS_FILE" | cut -d'=' -f2 | tr -d '"')
        echo "${val:-$default}"
    else
        echo "$default"
    fi
}

# === 辅助函数：写入 settings.cfg 的值 ===
set_setting() {
    local key="$1"
    local value="$2"
    [ ! -f "$SETTINGS_FILE" ] && touch "$SETTINGS_FILE"
    
    if grep -q "^${key}=" "$SETTINGS_FILE"; then
        sed -i "/^${key}=/c\\${key}=\"${value}\"" "$SETTINGS_FILE"
    else
        echo "${key}=\"${value}\"" >> "$SETTINGS_FILE"
    fi
}

# --- 1. 处理启动逻辑 (true 或 START_ONLY) ---
if [ "${1}" == "true" ] || [ "${1}" == "START_ONLY" ]; then
    echo "FileBrowser 准备启动" | tee >(logger -t "$TAG")
    
    # 只有当参数是用户手动触发的 "true" 时，才修改配置文件
    if [ "${1}" == "true" ]; then
        set_setting "filebrowser_ENABLED" "true"
    fi
    
    # 物理探测：回归用户要求的 $(basename "$BINARY") 模式
    if pgrep -f "$(basename "$BINARY")" > /dev/null 2>&1 ; then
        echo "FileBrowser 已经在运行！" | tee >(logger -t "$TAG")
        exit 0
    fi

    echo "FileBrowser 正在启动中..." | tee >(logger -t "$TAG")
    echo "$BINARY -c $CONFIG_YAML" | at now -M > /dev/null 2>&1

    # 循环检测启动结果
    for i in {1..5}; do
        sleep 1
        if pgrep -f "$(basename "$BINARY")" > /dev/null 2>&1 ; then
            # 缩减观察期：遵照用户建议，从 8 秒调整为 7 秒
            echo "发现进程，正在进行 7 秒稳定性核查..." | tee >(logger -t "$TAG")
            sleep 7
            if pgrep -f "$(basename "$BINARY")" > /dev/null 2>&1 ; then
                echo "FileBrowser 启动成功且运行稳定！" | tee >(logger -t "$TAG")
                # v2 迁移收尾：SQLite 已生成则移除一次性 migrateFrom，
                # 避免旧 BoltDB 备份被删除后 v2 拒绝启动
                SQLDB="$PLG_PATH/filebrowser.sqlite"
                if [ -s "$SQLDB" ] && grep -q '^    migrateFrom:' "$CONFIG_YAML" 2>/dev/null; then
                    sed -i '/^    migrateFrom:/d' "$CONFIG_YAML"
                    echo "数据库迁移已完成，已自动移除 config.yaml 中的 migrateFrom" | tee >(logger -t "$TAG")
                fi
                exit 0
            else
                echo "警告：进程在启动初期崩坏，请检查配置！" | tee >(logger -t "$TAG")
                exit 1
            fi
        fi
    done
    
    echo -e "\e[41m FileBrowser 启动失败 , 请检查设置和日志 . \e[0m" | tee >(logger -t "$TAG")
    exit 1

# --- 2. 处理停止逻辑 (false 或 STOP_ONLY) ---
elif [ "${1}" == "false" ] || [ "${1}" == "STOP_ONLY" ]; then
    echo "正在停止 FileBrowser..." | tee >(logger -t "$TAG")
    
    # 物理停止：精准打击 (pkill -9 是秒杀，无需循环等待)
    pkill -9 -f "$(basename "$BINARY")" > /dev/null 2>&1
    
    if [ "${1}" == "false" ]; then
        set_setting "filebrowser_ENABLED" "false"
    fi
    
    echo "FileBrowser 已停止" | tee >(logger -t "$TAG")
    exit 0

# --- 3. 获取端口 ---
elif [ "${1}" == "GET_PORT" ]; then
    if [ -f "$CONFIG_YAML" ]; then
        PORT=$(grep -E '^port:|^  port:' "$CONFIG_YAML" | head -n 1 | awk -F: '{print $2}' | tr -d '" ' | tr -d "'")
        echo "${PORT:-8081}"
    else
        echo "8081"
    fi
    exit 0

# --- 4. 获取本地版本号 ---
elif [ "${1}" == "GET_LOCAL_VER" ]; then
    if [ -f "$BINARY" ]; then
        # v1/v2 版本输出格式不同，统一用正则提取 vX.Y.Z(-后缀)
        VER=$("$BINARY" version 2>/dev/null | grep -oiE 'v[0-9]+\.[0-9]+\.[0-9]+(-[a-z0-9.]+)?' | head -n 1)
        echo "${VER:-unknown}"
    else
        echo "not installed"
    fi
    exit 0

# --- 5. 获取远程版本号并更新缓存 ---
elif [ "${1}" == "VERSION" ]; then
    [ ! -d "$INSTALL_PATH" ] && mkdir -p "$INSTALL_PATH"
    TAG_LIST=$(curl -s --connect-timeout 10 "https://api.github.com/repos/$GITHUB_REPO/tags" | grep '"name":' | head -n 10)
    
    # 统一存储：从 settings.cfg 获取分支
    BRANCH=$(get_setting "filebrowser_BRANCH" "stable")
    
    if [ "$BRANCH" == "beta" ]; then
        LAT_V=$(echo "$TAG_LIST" | grep -oE 'v[0-9]+\.[0-9]+\.[0-9]+-beta' | head -n 1)
    else
        LAT_V=$(echo "$TAG_LIST" | grep -oE 'v[0-9]+\.[0-9]+\.[0-9]+-stable' | head -n 1)
    fi
    
    if [ -n "$LAT_V" ]; then
        # 统一存储：写入 settings.cfg (不再支持物理 marker 文件)
        set_setting "filebrowser_LATEST" "$LAT_V"
        echo "$LAT_V"
        exit 0
    else
        echo "Unknown"
        exit 1
    fi

# --- 6. 设置分支 ---
elif [ "${1}" == "SET_BRANCH" ]; then
    if [ "${2}" == "beta" ] || [ "${2}" == "stable" ]; then
        set_setting "filebrowser_BRANCH" "${2}"
        exit 0
    else
        exit 1
    fi

# --- 7. 获取分支 ---
elif [ "${1}" == "GET_BRANCH" ]; then
    get_setting "filebrowser_BRANCH" "stable"
    exit 0

# --- 8. 获取状态 (快捷探测 + 深度就绪检查) ---
elif [ "${1}" == "CHECK" ]; then
    PID=$(pgrep -f "$(basename "$BINARY")")
    if [ -n "$PID" ]; then
        # 进程在跑，进一步检查端口是否已就绪
        PORT=$(/bin/bash "$DAEMON_SCRIPT" "GET_PORT")
        # 使用更精确的正则匹配：端口后必须紧跟空格或地址终止符
        if netstat -lnt | grep -qE ":$PORT[[:space:]]+.*LISTEN"; then
            echo "fully_ready"
        else
            echo "running"
        fi
        exit 0
    else
        echo "stopped"
        exit 1
    fi

# --- 9. 重启服务 (SAVE 逻辑专用) ---
elif [ "${1}" == "RESTART" ]; then
    /bin/bash "$DAEMON_SCRIPT" "STOP_ONLY"
    /bin/bash "$DAEMON_SCRIPT" "START_ONLY"
    exit $?

# --- 10. 物理校验配置 (真理校验 - 绝对隔离版) ---
elif [ "${1}" == "VALIDATE" ]; then
    TEST_CONFIG="${2:-$CONFIG_YAML}"
    if [ ! -f "$TEST_CONFIG" ]; then echo "Config file not found"; exit 1; fi

    # 建立深度隔离的校验环境：
    #   1. 端口强制为 0   2. 日志定向 /dev/null
    #   3. 数据库强制 dummy 且清空 migrateFrom（v2 的 database 是 server.database 子块，用 awk 做块内替换）
    VAL_TMP="/tmp/fb_val_$(date +%s).yaml"
    awk '
      /^  database:/ { indb=1; print; next }
      indb && /^    path:/        { print "    path: \"/tmp/fb_val_dummy.db\""; next }
      indb && /^    migrateFrom:/ { print "    migrateFrom: \"\""; next }
      indb && /^[^ ]/             { indb=0 }
      { print }
    ' "$TEST_CONFIG" > "$VAL_TMP"
    sed -i 's/\(port:[[:space:]]*\).*/\10/' "$VAL_TMP"
    sed -i 's/\(output:[[:space:]]*\).*/\1"\/dev\/null"/' "$VAL_TMP"

    # 执行真理校验（v2 校验失败时延迟 5 秒才输出 FATAL，超时放宽到 15 秒）
    VAL_OUT=$(timeout 15s "$BINARY" -c "$VAL_TMP" 2>&1)
    rm -f "$VAL_TMP"

    if echo "$VAL_OUT" | grep -Ei "FATAL|ERROR" > /dev/null; then
        echo "$VAL_OUT" | sed 's/^[0-9\/ :]* //'
        exit 1
    fi
    exit 0

# --- 11. v1 → v2 配置/数据库迁移（幂等：无 v1 配置则直接跳过） ---
elif [ "${1}" == "MIGRATE_CONFIG" ]; then
    CFG="$CONFIG_YAML"
    SQLDB="$PLG_PATH/filebrowser.sqlite"
    [ ! -f "$CFG" ] && exit 0

    # v1 schema 特征：server 段下的 database 是带绝对路径的标量
    # （v2 的 database 是无值的父键 + 子块；注意 v2 的 port 也在 2 空格缩进下，不能用作判据）
    if ! grep -qE "^  database:[[:space:]]+[\"']?/" "$CFG"; then
        exit 0
    fi
    echo "检测到 v1 版配置，正在转换为 v2 结构..."

    # 提取 v1 键值（去除引号与首尾空格）
    pick() {
        grep -E "^  $1:" "$CFG" | head -n 1 | cut -d: -f2- \
          | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' \
                -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//"
    }
    PORT=$(pick port);    [ -z "$PORT" ] && PORT="8081"
    BASE=$(pick baseURL); [ -z "$BASE" ] && BASE="/"
    INTURL=$(pick internalUrl)
    EXTURL=$(pick externalUrl)
    DB=$(pick database)

    # 旧 BoltDB 更名 + 一次性迁移标记（SQLite 已有数据则跳过，保证幂等）
    MIGLINE=""
    if [ -n "$DB" ] && [ -f "$DB" ] && [ ! -s "$SQLDB" ]; then
        if mv "$DB" "$DB.old" 2>/dev/null; then
            MIGLINE="    migrateFrom: \"$DB.old\""
            echo "旧数据库已更名为 $(basename "$DB").old，首次启动时自动导入 SQLite"
        fi
    fi

    [ -f "$CFG.v1.bak" ] || cp "$CFG" "$CFG.v1.bak"
    cat > "$CFG" <<EOF
http:
  port: $PORT
  baseURL: "$BASE"
  internalUrl: "$INTURL"
  externalUrl: "$EXTURL"
server:
  database:
    path: "$SQLDB"
$MIGLINE
  logging:
    - levels: "info|debug|warning|error"
      output: "/var/log/filebrowser_quantum.log"
  sources:
    - path: "/"
      name: "Root Dir"
      config:
        rules:
          - folderPath: "/bin"
          - folderPath: "/boot"
          - folderPath: "/dev"
          - folderPath: "/etc"
          - folderPath: "/home"
          - folderPath: "/hugetlbfs"
          - folderPath: "/lib"
          - folderPath: "/lib64"
          - folderPath: "/opt"
          - folderPath: "/proc"
          - folderPath: "/root"
          - folderPath: "/run"
          - folderPath: "/sbin"
          - folderPath: "/sys"
          - folderPath: "/tmp"
          - folderPath: "/usr"
          - folderPath: "/var"
auth:
  methods:
    password:
      enabled: true
EOF
    echo "配置已转换为 v2 结构（旧文件备份: $CFG.v1.bak）"
    exit 0

fi

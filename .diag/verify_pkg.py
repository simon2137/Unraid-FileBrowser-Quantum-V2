#!/usr/bin/env python3
"""校验 archive/*.txz 与仓库当前提交是否一致（逐条目比对字节）。

为什么不能直接比工作区文件：
  CI（.github/workflows/build_txz.yml）用 Ubuntu 的 tar 重新打包并提交回仓库，
  本机 bsdtar / Python 打出的包字节必然不同；而 Windows 工作区受 core.autocrlf
  影响可能是 CRLF，直接与包内字节比对会出现假 DIFFER。
所以正确做法是：包内每个文件的字节 == 该路径在 HEAD 里的 git blob 字节。

用法：
  python .diag/verify_pkg.py                  # 用 .plg 里的 bundleversion
  python .diag/verify_pkg.py --version X.Y.Z  # 指定包版本
退出码：0 = 一致；1 = 有差异/缺文件
"""

import hashlib
import os
import subprocess
import sys
import tarfile

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC_PREFIX = "source/filebrowser_quantum/"

# Windows 控制台默认 GBK，中文与 ✗ 会直接抛 UnicodeEncodeError
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")


def bundle_version():
    with open(os.path.join(REPO, "FileBrowser_Quantum.plg"), encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if line.startswith("<!ENTITY bundleversion"):
                return line.split('"')[1]
    raise SystemExit("在 .plg 里找不到 bundleversion")


def git_blob(path):
    return subprocess.check_output(["git", "-C", REPO, "cat-file", "blob", "HEAD:" + path])


def main():
    if "--version" in sys.argv:
        version = sys.argv[sys.argv.index("--version") + 1]
    else:
        version = bundle_version()

    pkg = os.path.join(REPO, "archive", f"filebrowser_quantum-{version}-x86_64.txz")
    md5_path = pkg + ".md5"
    problems = []

    if not os.path.isfile(pkg):
        raise SystemExit("包不存在: " + pkg)

    # 1) md5 边车文件
    digest = hashlib.md5(open(pkg, "rb").read()).hexdigest()
    side = open(md5_path, encoding="ascii").read().split()[0] if os.path.isfile(md5_path) else "(缺失)"
    print(f"包    : {os.path.relpath(pkg, REPO)}  {os.path.getsize(pkg)} bytes")
    print(f"md5   : {digest}")
    print(f"边车  : {side}  -> {'OK' if side == digest else 'MISMATCH'}")
    if side != digest:
        problems.append("md5 边车与包内容不一致")

    # 2) 逐条目比对字节与权限
    packed = {}
    with tarfile.open(pkg, "r:xz") as tf:
        for m in tf.getmembers():
            name = m.name.lstrip("./")
            if not name or m.isdir():
                continue
            if not m.isfile():
                problems.append(f"非普通文件条目: {m.name} ({m.type})")
                continue
            data = tf.extractfile(m).read()
            packed[name] = (data, m.mode)

    print(f"条目  : {len(packed)} 个文件")
    for name in sorted(packed):
        data, mode = packed[name]
        want = git_blob(SRC_PREFIX + name)
        flag = "OK" if data == want else "DIFFER"
        if data != want:
            problems.append(f"{name}: 包内 {len(data)}B != 仓库 blob {len(want)}B")
        if mode != 0o644:
            problems.append(f"{name}: 权限 {oct(mode)} != 0o644")
        print(f"  [{flag}] {name}  {len(data)}B  {oct(mode)}")

    # 3) 源目录里是否有漏打的文件（包内路径相对于 source/filebrowser_quantum/）
    src_dir = os.path.join(REPO, "source", "filebrowser_quantum")
    expected = sorted(
        os.path.relpath(os.path.join(root, f), src_dir).replace(os.sep, "/")
        for root, _dirs, files in os.walk(src_dir)
        for f in files
    )
    missing = [f for f in expected if f not in packed]
    extra = [f for f in packed if f not in expected]
    if missing:
        problems.append("漏打: " + ", ".join(missing))
    if extra:
        problems.append("多出: " + ", ".join(extra))
    print(f"源目录: {len(expected)} 个文件, 漏打 {len(missing)}, 多出 {len(extra)}")

    print()
    if problems:
        print("==== 校验失败 ====")
        for p in problems:
            print("  ✗ " + p)
        return 1
    print("==== 校验通过：包与 HEAD 完全一致 ====")
    return 0


if __name__ == "__main__":
    sys.exit(main())

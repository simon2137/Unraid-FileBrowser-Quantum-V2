#!/usr/bin/env python3
"""Build archive/filebrowser_quantum-<bundleversion>-x86_64.txz deterministically.

Why this script exists: the repo is developed on Windows, where the only tar is
bsdtar, which stamps Windows modes (0777/0666) into the archive. Slackware's
upgradepkg (what Unraid runs on install) expects the usual 0755 dirs / 0644
files, so we build the archive with the stdlib instead:

  * USTAR format, uid/gid 0/root, dirs 0755, files 0644 (matches the modes git
    tracks in source/), one fixed mtime for byte-reproducible output
  * xz compression (lzma preset 6), same as the CI's `tar -cJf`
  * bundleversion is read from FileBrowser_Quantum.plg, so the file name always
    matches &bundleversion; / &bundleURL;

Run from the repo root:  python .diag/build_pkg.py
"""

import calendar
import hashlib
import lzma
import os
import re
import sys
import tarfile

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(REPO, "source", "filebrowser_quantum")
PLG = os.path.join(REPO, "FileBrowser_Quantum.plg")
ARCHIVE_DIR = os.path.join(REPO, "archive")
MTIME = calendar.timegm((2026, 10, 15, 0, 0, 0))  # fixed for reproducibility


def bundle_version():
    with open(PLG, encoding="utf-8") as fh:
        m = re.search(r'ENTITY bundleversion "([^"]+)"', fh.read())
    if not m:
        sys.exit("bundleversion entity not found in " + PLG)
    return m.group(1)


def iter_entries():
    """Yield (relative_unix_path, is_dir, absolute_path) in stable sorted order."""
    entries = []
    for root, dirs, files in os.walk(SRC):
        dirs.sort()
        files.sort()
        rel_root = os.path.relpath(root, SRC).replace(os.sep, "/")
        if rel_root == ".":
            rel_root = ""
        for name in dirs:
            rel = f"{rel_root}/{name}" if rel_root else name
            entries.append((rel, True, os.path.join(root, name)))
        for name in files:
            rel = f"{rel_root}/{name}" if rel_root else name
            entries.append((rel, False, os.path.join(root, name)))
    entries.sort(key=lambda e: e[0])
    return entries


def main():
    version = bundle_version()
    os.makedirs(ARCHIVE_DIR, exist_ok=True)
    out = os.path.join(ARCHIVE_DIR, f"filebrowser_quantum-{version}-x86_64.txz")

    with lzma.open(out, "wb", preset=6) as raw:
        with tarfile.open(fileobj=raw, mode="w", format=tarfile.USTAR_FORMAT) as tar:
            for rel, is_dir, abspath in iter_entries():
                info = tar.gettarinfo(abspath, arcname=rel)
                info.mtime = MTIME
                info.uid = info.gid = 0
                info.uname = info.gname = "root"
                info.mode = 0o755 if is_dir else 0o644
                if is_dir:
                    info.size = 0
                    tar.addfile(info)
                else:
                    with open(abspath, "rb") as fh:
                        tar.addfile(info, fh)

    with open(out, "rb") as fh:
        digest = hashlib.md5(fh.read()).hexdigest()
    with open(out + ".md5", "w", encoding="ascii", newline="") as fh:
        fh.write(digest)

    print(f"{os.path.relpath(out, REPO)}  {os.path.getsize(out)} bytes")
    print(f"md5 {digest}")


if __name__ == "__main__":
    main()

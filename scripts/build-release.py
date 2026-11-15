#!/usr/bin/env python3
"""Build a clean DLE Plugin Manager archive from the manifest allowlist."""
from __future__ import annotations

import hashlib
import sys
import zipfile
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_OUT = ROOT / "dist" / "dle_peestream_v1.0.5-beta.1.zip"


def main() -> int:
    output = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else DEFAULT_OUT
    manifest = ET.parse(ROOT / "peestream.xml").getroot()
    paths = ["peestream.xml"]
    paths.extend(p.strip() for p in (manifest.findtext("filelist") or "").split(",") if p.strip())
    paths.extend(["README.md", "CHANGELOG.md", "LICENSE"])
    paths.extend(sorted(str(p.relative_to(ROOT)) for p in (ROOT / "assets").rglob("*") if p.is_file()))

    unique = list(dict.fromkeys(paths))
    files: list[tuple[str, Path]] = []
    for rel in unique:
        path = (ROOT / rel).resolve()
        if not path.is_file() or ROOT.resolve() not in path.parents:
            raise SystemExit(f"ERROR: missing or out-of-repository package candidate: {rel}")
        if any(part in {".git", "tests", "scripts", "upload", "dist"} for part in Path(rel).parts):
            raise SystemExit(f"ERROR: development artifact entered installer allowlist: {rel}")
        files.append((rel, path))

    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for rel, path in files:
            archive.write(path, rel)

    digest = hashlib.sha256(output.read_bytes()).hexdigest()
    print(f"Created {output} ({output.stat().st_size} bytes, {len(files)} files)")
    print(f"SHA-256 {digest}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env bash
# Byte-reproducible DLE Plugin Manager ZIP for the PeeStream DLE addon.
# Fixed timestamps + manifest allowlist + explicit compression, so two runs with the
# same sources always produce the same SHA-256. Writes dist/ and updates SHA256SUMS.
set -euo pipefail
cd "$(dirname "$0")/.."

VER=$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' peestream.xml | head -1)
[ -n "$VER" ] || { echo "ERROR: cannot read <version> from peestream.xml" >&2; exit 1; }

NAME="dle_peestream_v${VER}.zip"

mkdir -p dist
python3 - "$NAME" <<'PY'
import os, sys, zipfile
import xml.etree.ElementTree as ET

name = sys.argv[1]
manifest = ET.parse("peestream.xml").getroot()
paths = ["peestream.xml"]
paths += [p.strip() for p in (manifest.findtext("filelist") or "").split(",") if p.strip()]
paths += ["README.md", "CHANGELOG.md", "LICENSE"]
for dirpath, dirnames, filenames in os.walk("assets"):
    dirnames.sort()
    for fn in sorted(filenames):
        paths.append(os.path.join(dirpath, fn))

files = sorted(dict.fromkeys(paths))
for rel in files:
    if not os.path.isfile(rel):
        raise SystemExit("ERROR: missing package candidate %s" % rel)
    if any(part in {".git", "tests", "scripts", "upload", "dist"} for part in rel.split(os.sep)):
        raise SystemExit("ERROR: development artifact entered installer allowlist: %s" % rel)

stamp = (2026, 11, 15, 0, 0, 0)   # fixed, keeps the archive reproducible
out = os.path.join("dist", name)
with zipfile.ZipFile(out, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for rel in files:
        with open(rel, "rb") as fh:
            data = fh.read()
        info = zipfile.ZipInfo(rel, date_time=stamp)
        info.compress_type = zipfile.ZIP_DEFLATED
        info.create_system = 3
        info.external_attr = (0o644 & 0xFFFF) << 16
        z.writestr(info, data, compresslevel=9)
print("built %s (%d bytes, %d files)" % (out, os.path.getsize(out), len(files)))
PY

# SHA256SUMS covers exactly the artifact built here, so `sha256sum -c` passes on a
# fresh clone without keeping historical archives (those live on GitHub Releases).
python3 - "$NAME" <<'PY'
import hashlib, os, sys
name = sys.argv[1]
path = os.path.join("dist", name)
digest = hashlib.sha256(open(path, "rb").read()).hexdigest()
with open("SHA256SUMS", "w") as fh:
    fh.write("%s  %s\n" % (digest, name))
PY

echo "version ${VER}"
cat SHA256SUMS
echo "verify with: ( cd dist && sha256sum -c ../SHA256SUMS )"

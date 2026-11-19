#!/usr/bin/env python3
"""Build only the distributable plugin; exclude repository and development files."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
import json
import re

ROOT = Path(__file__).resolve().parent.parent
SLUG = 'church-website-connector'


def main():
    header = (ROOT / (SLUG + '.php')).read_text()
    version = re.search(r"const VERSION\s*=\s*'([^']+)';", header).group(1)
    assert re.fullmatch(r'(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)', version)
    assert re.search(r'Version: ' + re.escape(version) + r'\s', header)
    assert 'Stable tag: ' + version + '\n' in (ROOT / 'readme.txt').read_text()
    files = [ROOT / name for name in [SLUG + '.php', 'uninstall.php', 'readme.txt', 'LICENSE']]
    files += sorted((ROOT / 'includes').glob('*.php'))
    files += [ROOT / 'assets/setup.js']
    output = ROOT / 'dist'
    output.mkdir(exist_ok=True)
    archive = output / (SLUG + '-' + version + '.zip')
    with ZipFile(archive, 'w', ZIP_DEFLATED) as package:
        for path in sorted(files):
            assert path.is_file() and not path.is_symlink()
            entry = ZipInfo(str(Path(SLUG) / path.relative_to(ROOT)), (2026, 1, 1, 0, 0, 0))
            entry.compress_type = ZIP_DEFLATED
            entry.external_attr = 0o100644 << 16
            package.writestr(entry, path.read_bytes())
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    metadata = {
        'version': version,
        'requires': re.search(r'Requires at least: ([0-9.]+)', header).group(1),
        'requires_php': re.search(r'Requires PHP: ([0-9.]+)', header).group(1),
        'tested': '7.1.5',
        'sha256': digest,
    }
    manifest = output / (SLUG + '-' + version + '.json')
    manifest.write_text(json.dumps(metadata, indent=2) + '\n')
    (output / 'SHA256SUMS').write_text(digest + '  ' + archive.name + '\n')
    print(json.dumps({'version': version, 'zip': str(archive), 'metadata': str(manifest), 'sha256': digest}, indent=2))


if __name__ == '__main__':
    main()

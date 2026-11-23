#!/usr/bin/env python3
"""Validate native PHP integration outputs with Gutenberg from the matching WordPress ZIP."""
import argparse
import html
import json
from pathlib import Path
import re
import subprocess
import tempfile
import zipfile

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('wordpress_zip', type=Path)
parser.add_argument('cases', type=Path, help='pdf-editor-cases.json from pdf-editor-integration.php')
parser.add_argument('--chrome', required=True, help='Chromium/Chrome executable')
args = parser.parse_args()
inputs = json.loads(args.cases.read_text())
with tempfile.TemporaryDirectory(prefix='pdf-editor-') as folder, zipfile.ZipFile(args.wordpress_zip) as archive:
    root = Path(folder)
    version = archive.read('wordpress/wp-includes/version.php').decode()
    assert re.search(r"\$wp_version\s*=\s*'([^']+)'", version)[1] == inputs['wordpress']
    deps = archive.read('wordpress/wp-includes/assets/script-loader-packages.php').decode()
    packages = {pkg: re.findall("'([^']+)'", names) for pkg, names in
                re.findall(r"'([^']+)\.js' => array\(\s*'dependencies' => array\((.*?)\)", deps, re.S)}
    scripts = []
    visited = set()

    def include(handle):
        if handle in visited:
            return
        visited.add(handle)
        package = handle.removeprefix('wp-')
        for dependency in packages.get(package, []):
            include(dependency)
        name = (package if package in packages else 'vendor/' + handle) + '.min.js'
        location = 'wordpress/wp-includes/js/dist/' + name
        if location in archive.namelist():
            (root / (handle + '.js')).write_bytes(archive.read(location))
            scripts.append('<script src="' + handle + '.js"></script>')

    include('wp-block-library')
    (root / 'cases.js').write_text('window.inputs=' + json.dumps(inputs['cases']))
    verifier = """
try {
  const selected = wp.blockLibrary.__experimentalGetCoreBlocks().filter(
    block => ['core/file', 'core/spacer'].includes(block.metadata?.name ?? block.name)
  );
  wp.blockLibrary.registerCoreBlocks(selected);
  for (const [name, test] of Object.entries(window.inputs)) {
    const [file, spacer] = wp.blocks.parse(test.saved);
    const a = file.attributes;
    const template = document.createElement('template');
    template.innerHTML = wp.blocks.getSaveContent(file.name, a);
    const nameText = template.content.querySelector('a:not([download])')?.textContent;
    if (file.name !== 'core/file' || !file.isValid || !wp.blocks.validateBlock(file)[0] ||
        spacer.name !== 'core/spacer' || !spacer.isValid || a.id !== test.id || a.href !== test.url ||
        a.textLinkHref !== test.url || nameText !== test.label ||
        a.fileId !== 'wp-block-file--media-test' || (a.textLinkTarget ?? null) !== test.target ||
        a.previewHeight !== 880 || a.displayPreview !== test.preview ||
        a.showDownloadButton !== test.button || (test.button && String(a.downloadButtonText) !== 'Download issue')) {
      throw new Error('Invalid replacement or lost setting: ' + name);
    }
    const original = wp.blocks.parse(test.source)[0];
    if (original.isValid === (name === 'faulty-label-0')) throw new Error('Invalid source control: ' + name);
  }
  document.getElementById('verdict').textContent = 'PASSED';
} catch (err) { document.getElementById('verdict').textContent = String(err); }
"""
    (root / 'validate.js').write_text(verifier)
    page = root / 'index.html'
    page.write_text('<!DOCTYPE html><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" '
                    'content="default-src \'none\'; script-src \'self\'; style-src \'unsafe-inline\'; '
                    'connect-src \'none\'; object-src \'none\'"><pre id="verdict">unexecuted</pre>' +
                    ''.join(scripts) + '<script src="cases.js"></script><script src="validate.js"></script>')
    result = subprocess.run([args.chrome, '--headless', '--disable-extensions', '--disable-background-networking',
                             '--no-first-run', '--no-default-browser-check', '--host-resolver-rules=MAP * ~NOTFOUND',
                             '--user-data-dir=' + str(root / 'profile'), '--dump-dom', page.as_uri()],
                            capture_output=True, text=True, timeout=90, check=True)
    verdict = re.search(r'<pre id="verdict">([^<]+)</pre>', result.stdout)
    if not verdict or verdict[1] != 'PASSED':
        raise RuntimeError(html.unescape(verdict[1]) if verdict else result.stderr[-2000:])
    print(f"WordPress {inputs['wordpress']} editor validated all {len(inputs['cases'])} saved replacements.")

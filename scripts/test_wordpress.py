#!/usr/bin/env python3
"""Install the release ZIP and exercise the native WordPress updater in Playground."""
import argparse
import hashlib
import json
import re
import shutil
import subprocess
import tempfile
from pathlib import Path
from urllib.request import urlopen
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[1]
SLUG = 'church-website-connector'


def latest_wordpress():
    with urlopen('https://api.wordpress.org/core/version-check/1.7/', timeout=30) as response:
        offers = json.load(response)['offers']
    return next(offer['current'] for offer in offers if offer.get('response') in ('upgrade', 'latest')
                and re.fullmatch(r'[0-9]+\.[0-9]+(?:\.[0-9]+)?', offer.get('current', '')))


def future_zip(archive, target, version, future_version):
    with ZipFile(archive) as current, ZipFile(target, 'w') as future:
        for name in current.namelist():
            data = current.read(name)
            if name.endswith('/church-website-connector.php'):
                data = data.replace(f'Version: {version}'.encode(), f'Version: {future_version}'.encode())
                pattern = rb"(const VERSION\s*=\s*')" + re.escape(version.encode()) + rb"(')"
                data, changed = re.subn(pattern, lambda m: m[1] + future_version.encode() + m[2], data)
                if changed != 1:
                    raise ValueError('Could not prepare the future version fixture')
            elif name.endswith('/readme.txt'):
                data = data.replace(f'Stable tag: {version}'.encode(), f'Stable tag: {future_version}'.encode())
            future.writestr(name, data)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--cli', default='wp-playground-cli')
    args = parser.parse_args()
    wp = latest_wordpress()
    header = (ROOT / (SLUG + '.php')).read_text()
    version = re.search(r"const VERSION\s*=\s*'(\d+\.\d+\.\d+)';", header)[1]
    prefix, patch = version.rsplit('.', 1)
    future = f'{prefix}.{int(patch) + 1}'
    stem = f'{SLUG}-{version}'
    archive = ROOT / 'dist' / (stem + '.zip')
    metadata = json.loads((ROOT / 'dist' / (stem + '.json')).read_text())
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    if metadata['sha256'] != digest or metadata['version'] != version:
        raise ValueError('Release ZIP and metadata differ')
    with tempfile.TemporaryDirectory(prefix='connector-release-check-') as directory:
        tmp = Path(directory).resolve()
        reports, updates = tmp / 'reports', tmp / 'updates'
        reports.mkdir()
        updates.mkdir()
        shutil.copyfile(archive, tmp / archive.name)
        future_zip(archive, updates / 'future.zip', version, future)
        tests = ROOT / 'tests'
        template = (tests / 'wordpress-blueprint.json').read_text()
        blueprint = json.loads(template.replace('__CONNECTOR_VERSION__', version)
                               .replace('__CONNECTOR_ZIP_SHA256__', digest))
        blueprint['preferredVersions'] = {'wp': wp, 'php': '8.3'}
        for name in ['styled-overlay.php', 'publication-guards.php', 'metadata-guards.php', 'settings-safety.php', 'used-css-grants.php', 'maintenance-integration.php', 'updates-integration.php']:
            code = (tests / name).read_text().replace('__CURRENT_VERSION__', version).replace('__FUTURE_VERSION__', future)
            if name == 'used-css-grants.php':
                code = code.replace('<?php', "<?php define('CWC_TEST_USED_CSS_GRANTS', true);", 1)
            blueprint['steps'].insert(-1, {'step': 'runPHP', 'code': code})
        path = tmp / 'blueprint.json'
        path.write_text(json.dumps(blueprint))
        subprocess.run([args.cli, 'run-blueprint', '--wp=' + wp, '--php=8.3',
                        '--site-url=https://connector.example', '--define-bool', 'WP_DEBUG', 'true',
                        '--define-bool', 'WP_DEBUG_DISPLAY', 'true', '--define-bool', 'WP_DISABLE_FATAL_ERROR_HANDLER', 'true',
                        '--mount=' + str(reports) + ':/reports', '--mount=' + str(updates) + ':/update-fixtures',
                        '--blueprint=' + str(path), '--blueprint-may-read-adjacent-files'], check=True, timeout=300)
        def report(name):
            return json.loads((reports / (name + '.json')).read_text())
        install, status, admin, updater, rest = map(report, ['installation', 'status', 'maintenance', 'github-updates', 'rest-tools'])
        def version_parts(value):
            parts = value.split('.')
            return parts if len(parts) == 3 else parts + ['0']
        if version_parts(install['wordpress']) != version_parts(wp):
            raise ValueError(f"Requested WordPress {wp}, booted {install['wordpress']}")
        if not (install['php'].startswith('8.3.') and install['admin_html_rendered'] and install['zip_sha256'] == digest
                and status['anonymous_status_denied'] and admin['read_only_views'] and admin['admin_permissions']
                and updater['native_upgrade_completed'] and updater['tampered_package_rejected']
                and updater['settings_and_grants_preserved'] and rest['permissions_preserved'] and report('styled-overlay')['published']):
            raise RuntimeError('Required native checks did not pass')
        metadata_guards = report('metadata-guards')
        if not metadata_guards['checks'] or metadata_guards['checks'] != len(metadata_guards['cases']):
            raise RuntimeError('Metadata, schedule and audit regressions are incomplete')
        settings = report('settings-safety')
        if not settings['checks'] or settings['checks'] != len(settings['cases']):
            raise RuntimeError('Settings and timezone regression results are incomplete')
        grants = report('used-css-grants')
        if not grants['checks'] or grants['checks'] != len(grants['cases']):
            raise RuntimeError('Used CSS capability regression checks are incomplete')
        print(f"Passed on WordPress {install['wordpress']} and PHP {install['php']}. "
              f"{grants['checks']} Used CSS grant, {settings['checks']} settings and timezone, {admin['checks']} maintenance, {updater['checks']} updater, {rest['checks']} REST checks. ZIP SHA-256 {digest}")


if __name__ == '__main__':
    main()

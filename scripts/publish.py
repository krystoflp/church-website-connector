#!/usr/bin/env python3
"""Publish a prepared package atomically through a draft release, using Actions credentials."""
from pathlib import Path
import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parent.parent
REPO = 'krystoflp/church-website-connector'


def request(url, token, data=None, content_type='application/json', method=None):
    headers = {'Authorization': 'Bearer ' + token, 'Accept': 'application/vnd.github+json', 'Content-Type': content_type}
    payload = json.dumps(data).encode() if isinstance(data, dict) else data
    req = urllib.request.Request(url, data=payload, headers=headers, method=method)
    with urllib.request.urlopen(req, timeout=60) as response:
        return json.load(response)


def main():
    assert os.environ.get('GITHUB_REPOSITORY') == REPO, 'Run in the intended release repository'
    token = os.environ['GH_TOKEN']
    revision = os.environ['GITHUB_SHA']
    assert re.fullmatch(r'[a-f0-9]{40}', revision)
    header = (ROOT / 'church-website-connector.php').read_text()
    version = re.search(r"const VERSION = '([^']+)';", header).group(1)
    assert re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+', version)
    tag = 'v' + version
    api = 'https://api.github.com/repos/' + REPO + '/releases'
    try:
        request(api + '/tags/' + tag, token)
    except urllib.error.HTTPError as error:
        if error.code != 404:
            raise
    else:
        raise RuntimeError('This version already has a release. Inspect it or publish a new version; do not overwrite it.')
    names = ['church-website-connector-' + version + '.zip', 'church-website-connector-' + version + '.json']
    for name in names:
        assert (ROOT / 'dist' / name).is_file()
    readme = (ROOT / 'readme.txt').read_text()
    match = re.search(r'^= ' + re.escape(version) + r' =\n(.*?)(?=\n= |\Z)', readme, re.M | re.S)
    assert match, 'Release notes must be present'
    release = request(api, token, {'tag_name': tag, 'target_commitish': revision, 'name': 'Church Website Connector ' + version, 'body': match.group(1).strip(), 'draft': True, 'prerelease': False})
    release_id = release['id']
    assert isinstance(release_id, int)
    for name in names:
        upload = 'https://uploads.github.com/repos/' + REPO + '/releases/' + str(release_id) + '/assets?name=' + urllib.parse.quote(name)
        result = request(upload, token, (ROOT / 'dist' / name).read_bytes(), 'application/octet-stream')
        assert result['state'] == 'uploaded' and result['name'] == name
    published = request(api + '/' + str(release_id), token, {'draft': False, 'make_latest': 'true'}, method='PATCH')
    assert published['draft'] is False and len(published['assets']) == 2
    print('Published ' + published['html_url'])


if __name__ == '__main__':
    main()

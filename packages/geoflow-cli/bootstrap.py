#!/usr/bin/env python3
"""Pinned public CLI bootstrap. Verify this script's attestation before running it."""
import argparse
import base64
import contextlib
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tarfile
import tempfile
import urllib.error
import urllib.parse
import urllib.request

REPOSITORY = 'yaojingang/GEOFlow'
INSTALLER = {'install.php', 'StandaloneArguments.php', 'StandaloneFiles.php', 'StandaloneBundle.php', 'StandaloneInstaller.php'}
CANDIDATE_FILES = INSTALLER | {'geoflow.phar', 'manifest.json', 'LICENSE', 'provenance.json'}
RELEASE_FILES = CANDIDATE_FILES | {'manifest.sig', 'trust.json'}
MAX_BYTES = 128 * 1024 * 1024
VERSION = re.compile(r'\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?\Z')
DIGEST = re.compile(r'[a-f0-9]{64}\Z')
COMMIT = re.compile(r'(?:[a-f0-9]{40}|[a-f0-9]{64})\Z')


def digest(path):
    with open(path, 'rb') as stream:
        value = hashlib.sha256()
        while chunk := stream.read(65536):
            value.update(chunk)
        return value.hexdigest()


def regular(path):
    if path.is_symlink() or (path.exists() and (not path.is_file() or path.stat().st_nlink != 1)):
        raise ValueError('Expected a regular file with one link: ' + path.name)


def atomic(path, content):
    regular(path)
    descriptor, name = tempfile.mkstemp(prefix='.geoflow-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        regular(path)
        os.replace(name, path)
        fd = os.open(path.parent, os.O_RDONLY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
    finally:
        if os.path.exists(name):
            os.unlink(name)


@contextlib.contextmanager
def locked(directory, name):
    directory = Path(directory).absolute()
    if directory.is_symlink():
        raise ValueError('Directory must not be a symbolic link')
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    path = directory / name
    regular(path)
    fd = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        if os.fstat(fd).st_nlink != 1:
            raise ValueError('Lock must have one link')
        fcntl.flock(fd, fcntl.LOCK_EX)
        yield directory.resolve()
    finally:
        os.close(fd)


def allowed_url(url):
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != 'https' or parsed.hostname not in {'github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'} or parsed.port not in (None, 443) or parsed.username or parsed.password:
        raise ValueError('Download URL is outside the official HTTPS asset origins')


class OfficialRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, newurl):
        allowed_url(newurl)
        return super().redirect_request(request, fp, code, message, headers, newurl)


def fetch(url, destination, expected, opener=None):
    """Resume only the same pinned digest. No authentication headers are sent."""
    if not DIGEST.fullmatch(expected):
        raise ValueError('An exact SHA-256 is required')
    allowed_url(url)
    opener = opener or urllib.request.build_opener(OfficialRedirects()).open
    destination = Path(destination)
    partial, metadata = Path(str(destination) + '.part'), Path(str(destination) + '.download.json')
    for path in (destination, partial, metadata):
        regular(path)
    if destination.exists() and digest(destination) == expected:
        return destination
    identity = {'url': url, 'sha256': expected}
    if metadata.exists() and metadata.stat().st_size > 8192:
        raise ValueError('Download recovery metadata exceeds limit')
    prior = json.loads(metadata.read_text()) if metadata.exists() else {}
    if any(prior.get(key) != value for key, value in identity.items()):
        if partial.exists():
            partial.unlink()
        atomic(metadata, json.dumps(identity).encode())
        prior = identity
    for attempt in range(3):
        offset = partial.stat().st_size if partial.exists() else 0
        if offset > MAX_BYTES:
            raise ValueError('Partial download exceeds the size limit')
        headers = {'User-Agent': 'GEOFlow-CLI-bootstrap', 'Accept-Encoding': 'identity'}
        if offset:
            headers['Range'] = f'bytes={offset}-'
            if prior.get('etag'):
                headers['If-Range'] = prior['etag']
        try:
            response = opener(urllib.request.Request(url, headers=headers), timeout=30)
        except urllib.error.HTTPError as error:
            if error.code == 416 and offset:
                partial.unlink()
                continue
            raise
        with response:
            status = response.status
            etag = response.headers.get('ETag')
            if status == 206:
                match = re.fullmatch(r'bytes (\d+)-(\d+)/(\d+)', response.headers.get('Content-Range', ''))
                if not match or int(match[1]) != offset or int(match[2]) < offset or int(match[2]) >= int(match[3]) or int(match[3]) > MAX_BYTES:
                    raise ValueError('Invalid resumed response range')
                if prior.get('etag') and etag != prior['etag']:
                    partial.unlink(missing_ok=True)
                    prior = identity
                    atomic(metadata, json.dumps(prior).encode())
                    continue
                total = int(match[3])
            elif status == 200:
                offset = 0
                length = response.headers.get('Content-Length')
                total = int(length) if length and length.isdigit() else None
                if total is not None and total > MAX_BYTES:
                    raise ValueError('Download exceeds the size limit')
            else:
                raise ValueError('Unexpected asset response status')
            prior = identity | {'etag': etag}
            atomic(metadata, json.dumps(prior).encode())
            flags = os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW | (os.O_APPEND if offset else os.O_TRUNC)
            with os.fdopen(os.open(partial, flags, 0o600), 'wb') as stream:
                if os.fstat(stream.fileno()).st_nlink != 1:
                    raise ValueError('Partial download must have one link')
                received = offset
                while chunk := response.read(65536):
                    received += len(chunk)
                    if received > MAX_BYTES or (total is not None and received > total):
                        raise ValueError('Asset response exceeded its declared size')
                    stream.write(chunk)
                stream.flush()
                os.fsync(stream.fileno())
            if total is not None and received != total:
                raise ValueError('Download interrupted; retry the same pinned release to resume')
        if digest(partial) != expected:
            partial.unlink()
            raise ValueError('Downloaded asset digest differs from the pinned release')
        os.replace(partial, destination)
        return destination
    raise ValueError('Asset changed during repeated resume attempts')


def extract(archive, destination, expected_files=RELEASE_FILES):
    """Inspect the entire fixed allowlist before creating any payload files."""
    if Path(archive).stat().st_size > MAX_BYTES:
        raise ValueError('Archive exceeds limit')
    with tarfile.open(archive, 'r:') as source:
        members = []
        for member in source:
            members.append(member)
            if len(members) > len(expected_files):
                raise ValueError('Archive exceeds the fixed member count')
        names = [member.name for member in members]
        if len(names) != len(set(names)) or set(names) != expected_files:
            raise ValueError('Archive file set differs from the fixed release allowlist')
        for member in members:
            if not member.isfile() or member.issparse() or member.pax_headers or '/' in member.name or '\\' in member.name or member.size < 1:
                raise ValueError('Archive contains a non-regular or unsafe entry')
            maximum = 100 * 1024 * 1024 if member.name == 'geoflow.phar' else 1024 * 1024
            if member.size > maximum or (member.mode & 0o7111 and member.name != 'geoflow.phar') or member.mode & 0o7000:
                raise ValueError('Archive contains an oversized or executable extra file')
        destination = Path(destination)
        destination.mkdir(mode=0o700, exist_ok=False)
        for member in members:
            content = source.extractfile(member).read(member.size + 1)
            if len(content) != member.size:
                raise ValueError('Archive entry length mismatch')
            with open(destination / member.name, 'xb') as target:
                target.write(content)
            os.chmod(destination / member.name, 0o700 if member.name == 'geoflow.phar' else 0o600)
    return destination


def trust_document(path):
    regular(path)
    if path.stat().st_size > 65536:
        raise ValueError('Trust bundle exceeds limit')
    trust = json.loads(path.read_text())
    expiry = datetime.datetime.strptime(trust.get('expires_at', ''), '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=datetime.timezone.utc)
    if trust.get('schema_version') != 1 or type(trust.get('version')) is not int or trust['version'] < 1 or expiry <= datetime.datetime.now(datetime.timezone.utc) or not isinstance(trust.get('keys'), dict):
        raise ValueError('Trust bundle is invalid or expired')
    if set(trust) != {'schema_version', 'version', 'expires_at', 'keys'} or len(trust['keys']) > 32:
        raise ValueError('Unexpected trust fields')
    for key_id, key in trust['keys'].items():
        if not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', key_id) or not isinstance(key, dict) or set(key) != {'public_key', 'status'} or key['status'] not in {'active', 'revoked'} or len(base64.b64decode(key['public_key'], validate=True)) != 32:
            raise ValueError('Invalid public trust key')
    return trust


def trust_identity(path):
    # Match the existing PHP installer's receipt encoding, including numeric key
    # IDs, empty maps and escaped slashes. Only this fixed local code executes.
    return subprocess.check_output(['php', '-r', 'echo hash("sha256",json_encode(json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR),JSON_THROW_ON_ERROR));', str(path)], text=True).strip()


def activate_trust(archive, bindir, updated):
    """Persist revocation before activation under the installer's transaction lock."""
    with locked(bindir, '.geoflow-install.lock'):
        trusted, state_path = bindir / '.geoflow-trust.json', bindir / '.geoflow-trust-state.json'
        identity = trust_identity(archive)
        for path in (trusted, state_path):
            regular(path)
            if path.exists() and path.stat().st_size > 65536:
                raise ValueError('Installed trust state exceeds limit')
        if trusted.exists():
            current = json.loads(trusted.read_text())
            if type(current.get('version')) is not int or updated['version'] < current['version'] or (updated['version'] == current['version'] and identity != trust_identity(trusted)):
                raise ValueError('Trust downgrade or same-version replacement rejected')
        receipt = {'schema_version': 1, 'version': updated['version'], 'sha256': identity}
        if state_path.exists():
            previous = json.loads(state_path.read_text())
            if previous.get('schema_version') != 1 or type(previous.get('version')) is not int or not isinstance(previous.get('sha256'), str) or previous['version'] < 0 or (previous['version'] > 0 and not DIGEST.fullmatch(previous['sha256'])):
                raise ValueError('Invalid installed trust high-water')
            if updated['version'] < previous['version'] or (updated['version'] == previous['version'] and identity != previous['sha256']):
                raise ValueError('Trust rollback or same-version replacement rejected')
        # A crash between these writes fails closed. Retrying these exact official
        # bytes completes activation; an archived root cannot reopen a revoked key.
        atomic(state_path, json.dumps(receipt).encode())
        atomic(trusted, archive.read_bytes())


def verify_attestation(path, workflow, source_commit):
    subprocess.run(['gh', 'attestation', 'verify', str(path), '--repo', REPOSITORY, '--signer-workflow', f'{REPOSITORY}/.github/workflows/{workflow}', '--source-ref', 'refs/heads/main', '--source-digest', source_commit, '--deny-self-hosted-runners'], check=True, stdout=subprocess.DEVNULL)


def verify_tag(tag, source_commit):
    item = json.loads(subprocess.check_output(['gh', 'api', f'repos/{REPOSITORY}/git/ref/tags/{tag}']))['object']
    for _ in range(4):
        if item['type'] == 'commit':
            if item['sha'] != source_commit:
                raise ValueError('Release tag does not match the pinned source commit')
            return
        if item['type'] != 'tag':
            break
        item = json.loads(subprocess.check_output(['gh', 'api', f'repos/{REPOSITORY}/git/tags/{item["sha"]}']))['object']
    raise ValueError('Unsupported release tag chain')


def main():
    parser = argparse.ArgumentParser(description=__doc__, allow_abbrev=False)
    parser.add_argument('action', choices=['install', 'update', 'trust'])
    parser.add_argument('--version', required=True)
    parser.add_argument('--source-commit', required=True)
    parser.add_argument('--sha256', required=True)
    parser.add_argument('--bin-dir', type=Path, required=True)
    parser.add_argument('--cache-dir', type=Path, required=True)
    args = parser.parse_args()
    if not COMMIT.fullmatch(args.source_commit) or not DIGEST.fullmatch(args.sha256):
        parser.error('Exact source commit and artifact SHA-256 are required')
    if args.action == 'trust':
        if not re.fullmatch(r'[1-9]\d*', args.version):
            parser.error('Trust version must be a positive integer')
        tag, filename, workflow = 'cli-trust-v' + args.version, 'trust.json', 'cli-trust.yml'
    else:
        if not VERSION.fullmatch(args.version):
            parser.error('CLI version must be semantic')
        tag, filename, workflow = 'cli-v' + args.version, f'geoflow-cli-{args.version}.tar', 'cli-sign.yml'
    verify_tag(tag, args.source_commit)
    with locked(args.cache_dir, '.download.lock') as cache, locked(args.bin_dir, '.geoflow-trust.lock') as bindir:
        archive = fetch(f'https://github.com/{REPOSITORY}/releases/download/{tag}/{filename}', cache / args.sha256, args.sha256)
        verify_attestation(archive, workflow, args.source_commit)
        trusted = bindir / '.geoflow-trust.json'
        regular(trusted)
        if args.action == 'trust':
            updated = trust_document(archive)
            if updated['version'] != int(args.version):
                raise ValueError('Trust release version mismatch')
            activate_trust(archive, bindir, updated)
            print(json.dumps({'trust_version': updated['version'], 'updated': True}))
            return
        with tempfile.TemporaryDirectory(prefix='.verified-', dir=cache) as stage:
            payload = extract(archive, Path(stage) / 'payload')
            manifest = json.loads((payload / 'manifest.json').read_text())
            if manifest.get('version') != args.version or manifest.get('source_commit') != args.source_commit or manifest.get('schema_version') != 2:
                raise ValueError('Release identity differs from requested version and commit')
            if not trusted.exists():
                trust_document(payload / 'trust.json')
                if (bindir / 'geoflow').exists():
                    raise ValueError('Existing installations must explicitly initialize official trust with the trust action')
                atomic(trusted, (payload / 'trust.json').read_bytes())
            trust_document(trusted)
            command = ['php', str(payload / 'install.php'), '--bundle', str(payload), '--trusted-keys', str(trusted), '--bin-dir', str(bindir)]
            if args.action == 'update':
                command.append('--update')
            subprocess.run(command, check=True)


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, subprocess.CalledProcessError, tarfile.TarError) as error:
        raise SystemExit(str(error))

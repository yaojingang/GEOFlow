#!/usr/bin/env python3
"""Build immutable CLI candidates and assemble approved release evidence."""
import argparse
import datetime
import io
import hashlib
import re
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
from bootstrap import CANDIDATE_FILES, RELEASE_FILES, INSTALLER, COMMIT, DIGEST, VERSION, REPOSITORY, digest, extract

ROOT = Path(__file__).resolve().parents[2]
PACKAGE = ROOT / 'packages/geoflow-cli'


def encode(value):
    return (json.dumps(value, sort_keys=True, indent=2) + '\n').encode()


def archive_files(files, output):
    output = Path(output)
    with open(output, 'xb') as stream, tarfile.open(fileobj=stream, mode='w', format=tarfile.USTAR_FORMAT) as archive:
        for name, content in sorted(files.items()):
            info = tarfile.TarInfo(name)
            info.size = len(content)
            info.mode = 0o755 if name == 'geoflow.phar' else 0o644
            info.mtime = 0
            archive.addfile(info, io.BytesIO(content))
        stream.flush()
    return digest(output)


def candidate(bundle, commit, sequence, output):
    actual = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
    dirty = subprocess.check_output(['git', 'status', '--porcelain', '--untracked-files=all'], cwd=ROOT, text=True).strip()
    if dirty or actual != commit or not COMMIT.fullmatch(commit) or sequence < 1:
        raise ValueError('Candidate source must be the exact clean committed checkout')
    manifest = json.loads((bundle / 'manifest.json').read_text())
    phar = (bundle / 'geoflow.phar').read_bytes()
    if digest(bundle / 'geoflow.phar') != manifest['sha256'] or len(phar) != manifest['size']:
        raise ValueError('Build archive and manifest disagree')
    version = json.loads(subprocess.check_output(['php', str(bundle / 'geoflow.phar'), '--version']))['version']
    if version != manifest['version'] or not VERSION.fullmatch(version):
        raise ValueError('Built executable version differs from the candidate')
    manifest.update(schema_version=2, release_sequence=sequence, source_commit=commit)
    files = {name: (PACKAGE / name).read_bytes() for name in INSTALLER}
    files.update({'geoflow.phar': phar, 'manifest.json': encode(manifest), 'LICENSE': (ROOT / 'LICENSE').read_bytes()})
    provenance = {'schema_version': 1, 'version': version, 'source_commit': commit, 'release_sequence': sequence, 'files': {name: hashlib.sha256(content).hexdigest() for name, content in files.items()}}
    files['provenance.json'] = encode(provenance)
    checksum = archive_files(files, output)
    return {'version': version, 'source_commit': commit, 'release_sequence': sequence, 'candidate_sha256': checksum}


def inspect(payload, expected_files):
    if {p.name for p in payload.iterdir()} != expected_files:
        raise ValueError('Unexpected release files')
    provenance = json.loads((payload / 'provenance.json').read_text())
    manifest = json.loads((payload / 'manifest.json').read_text())
    if provenance.get('schema_version') != 1 or manifest.get('schema_version') != 2:
        raise ValueError('Unsupported release provenance or manifest')
    if not COMMIT.fullmatch(manifest.get('source_commit', '')) or not VERSION.fullmatch(manifest.get('version', '')) or type(manifest.get('release_sequence')) is not int or manifest['release_sequence'] < 1:
        raise ValueError('Invalid immutable release identity')
    for field in ['version', 'source_commit', 'release_sequence']:
        if manifest.get(field) != provenance.get(field):
            raise ValueError('Candidate provenance identity mismatch')
    if set(provenance.get('files', {})) != CANDIDATE_FILES - {'provenance.json'}:
        raise ValueError('Incomplete candidate provenance')
    for name, expected in provenance['files'].items():
        if digest(payload / name) != expected:
            raise ValueError('Candidate file changed: ' + name)
    if digest(payload / 'geoflow.phar') != manifest['sha256'] or (payload / 'geoflow.phar').stat().st_size != manifest['size']:
        raise ValueError('PHAR does not match the release manifest')
    return manifest


def validate_candidate_lifetime(evidence, expected_digest, commit, now=None):
    required = {'schema_version', 'run_id', 'artifact_id', 'source_commit', 'candidate_sha256', 'created_at', 'expires_at'}
    if not isinstance(evidence, dict) or set(evidence) != required or evidence.get('schema_version') != 1 or any(type(evidence.get(key)) is not int or evidence[key] < 1 for key in ['run_id', 'artifact_id']):
        raise ValueError('Invalid original candidate lifetime evidence')
    if not DIGEST.fullmatch(expected_digest) or not COMMIT.fullmatch(commit) or evidence['candidate_sha256'] != expected_digest or evidence['source_commit'] != commit:
        raise ValueError('Original candidate lifetime identity mismatch')
    def timestamp(value):
        return datetime.datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=datetime.timezone.utc)
    created, expires = timestamp(evidence['created_at']), timestamp(evidence['expires_at'])
    now = now or datetime.datetime.now(datetime.timezone.utc)
    if expires <= created or created > now:
        raise ValueError('Invalid original candidate lifetime timestamps')
    # Signed-artifact retention cannot restart the original candidate's clock.
    if now >= min(expires, created + datetime.timedelta(days=30)):
        raise ValueError('Original candidate has expired; build and test a new candidate')
    return evidence


def candidate_lifetime(run_id, expected_digest, commit, artifact_id=None):
    if type(run_id) is not int or run_id < 1:
        raise ValueError('Exact candidate run ID required')
    run = json.loads(subprocess.check_output(['gh', 'api', f'repos/{REPOSITORY}/actions/runs/{run_id}']))
    if run.get('id') != run_id or run.get('conclusion') != 'success' or run.get('event') != 'workflow_dispatch' or run.get('head_branch') != 'main' or run.get('head_sha') != commit or run.get('path') != '.github/workflows/cli-candidate.yml':
        raise ValueError('Original candidate run identity is invalid')
    if artifact_id is None:
        pages = json.loads(subprocess.check_output(['gh', 'api', '--paginate', '--slurp', f'repos/{REPOSITORY}/actions/runs/{run_id}/artifacts?per_page=100']))
        matches = [item for page in pages for item in page['artifacts'] if item.get('name') == f'cli-candidate-{run_id}']
        if len(matches) != 1:
            raise ValueError('Exactly one immutable original candidate artifact is required')
        artifact = matches[0]
    else:
        artifact = json.loads(subprocess.check_output(['gh', 'api', f'repos/{REPOSITORY}/actions/artifacts/{artifact_id}']))
    if artifact.get('name') != f'cli-candidate-{run_id}' or artifact.get('expired') is not False or artifact.get('workflow_run', {}).get('id') != run_id or artifact.get('workflow_run', {}).get('head_sha') != commit or (artifact_id is not None and artifact.get('id') != artifact_id):
        raise ValueError('Original candidate artifact is expired or its identity changed')
    evidence = {'schema_version': 1, 'run_id': run_id, 'artifact_id': artifact['id'], 'source_commit': commit, 'candidate_sha256': expected_digest, 'created_at': artifact['created_at'], 'expires_at': artifact['expires_at']}
    return validate_candidate_lifetime(evidence, expected_digest, commit)


def verify_candidate_evidence(record):
    evidence = validate_candidate_lifetime(record.get('candidate'), record.get('candidate_sha256', ''), record.get('source_commit', ''))
    live = candidate_lifetime(evidence['run_id'], record['candidate_sha256'], record['source_commit'], evidence['artifact_id'])
    if live != evidence:
        raise ValueError('Original candidate artifact metadata changed after signing')
    return evidence


def sign(candidate_path, expected_digest, key, key_id, trust, output, evidence=None):
    if not DIGEST.fullmatch(expected_digest) or digest(candidate_path) != expected_digest:
        raise ValueError('Candidate differs from approved digest')
    with tempfile.TemporaryDirectory() as stage:
        payload = extract(candidate_path, Path(stage) / 'payload', CANDIDATE_FILES)
        manifest = inspect(payload, CANDIDATE_FILES)
        if evidence is not None:
            validate_candidate_lifetime(evidence, expected_digest, manifest['source_commit'])
        subprocess.run(['php', str(PACKAGE / 'sign.php'), '--bundle', str(payload), '--key-file', str(key), '--key-id', key_id, '--trust', str(trust)], check=True)
        files = {name: (payload / name).read_bytes() for name in RELEASE_FILES}
        checksum = archive_files(files, output)
    result = {'version': manifest['version'], 'source_commit': manifest['source_commit'], 'release_sequence': manifest['release_sequence'], 'candidate_sha256': expected_digest, 'archive_sha256': checksum}
    if evidence is not None:
        result['candidate'] = evidence
    return result


def release_set(document):
    if document.get('schema_version') != 1 or set(document.get('components', {})) != {'core', 'updater', 'cli', 'skill'}:
        raise ValueError('Release set must identify exactly Core, updater, CLI and skill')
    for name, component in document['components'].items():
        if not VERSION.fullmatch(component.get('version', '')) or not COMMIT.fullmatch(component.get('commit', '')) or not DIGEST.fullmatch(component.get('sha256', '')) or component['sha256'] == '0' * 64:
            raise ValueError('Missing exact component identity: ' + name)
        protocols = component.get('protocols')
        if not isinstance(protocols, dict) or not protocols:
            raise ValueError('Component must declare supported protocol ranges: ' + name)
        for protocol, bounds in protocols.items():
            if not re.fullmatch(r'[a-z][a-z0-9_]{0,63}', protocol) or not isinstance(bounds, dict) or set(bounds) != {'min', 'max'}:
                raise ValueError('Invalid protocol range')
            values = [bounds[key] for key in ['min', 'max']]
            if not all(isinstance(value, str) for value in values):
                raise ValueError('Protocol range bounds must be strings')
            if not all(re.fullmatch(r'\d+(?:\.\d+)*', value) for value in values) or tuple(map(int, values[0].split('.'))) > tuple(map(int, values[1].split('.'))):
                raise ValueError('Invalid protocol range bounds')
    return document


def main():
    parser = argparse.ArgumentParser(description=__doc__, allow_abbrev=False)
    commands = parser.add_subparsers(dest='action', required=True)
    build = commands.add_parser('candidate', allow_abbrev=False)
    build.add_argument('--bundle', type=Path, required=True)
    build.add_argument('--source-commit', required=True)
    build.add_argument('--sequence', type=int, required=True)
    build.add_argument('--output', type=Path, required=True)
    signer = commands.add_parser('sign', allow_abbrev=False)
    signer.add_argument('--candidate', type=Path, required=True)
    signer.add_argument('--candidate-evidence', type=Path, required=True)
    signer.add_argument('--sha256', required=True)
    signer.add_argument('--key-file', type=Path, required=True)
    signer.add_argument('--key-id', required=True)
    signer.add_argument('--trust', type=Path, required=True)
    signer.add_argument('--output', type=Path, required=True)
    verify = commands.add_parser('verify', allow_abbrev=False)
    verify.add_argument('--archive', type=Path, required=True)
    verify.add_argument('--trust', type=Path, required=True)
    smoke = commands.add_parser('test-candidate', allow_abbrev=False)
    smoke.add_argument('--archive', type=Path, required=True)
    evidence = commands.add_parser('release-set', allow_abbrev=False)
    evidence.add_argument('--input', type=Path, required=True)
    lifetime = commands.add_parser('candidate-evidence', allow_abbrev=False)
    lifetime.add_argument('--run-id', type=int, required=True)
    lifetime.add_argument('--sha256', required=True)
    lifetime.add_argument('--source-commit', required=True)
    recheck = commands.add_parser('verify-candidate', allow_abbrev=False)
    recheck.add_argument('--release', type=Path, required=True)
    args = parser.parse_args()
    if args.action == 'candidate':
        result = candidate(args.bundle, args.source_commit, args.sequence, args.output)
    elif args.action == 'sign':
        result = sign(args.candidate, args.sha256, args.key_file, args.key_id, args.trust, args.output, json.loads(args.candidate_evidence.read_text()))
    elif args.action == 'candidate-evidence':
        result = candidate_lifetime(args.run_id, args.sha256, args.source_commit)
    elif args.action == 'verify-candidate':
        result = verify_candidate_evidence(json.loads(args.release.read_text()))
    elif args.action == 'test-candidate':
        with tempfile.TemporaryDirectory() as stage:
            payload = extract(args.archive, Path(stage) / 'payload', CANDIDATE_FILES)
            inspect(payload, CANDIDATE_FILES)
            result = json.loads(subprocess.check_output(['php', str(PACKAGE / 'smoke.php'), '--candidate-bundle', str(payload)]))
    elif args.action == 'release-set':
        result = release_set(json.loads(args.input.read_text()))
    else:
        with tempfile.TemporaryDirectory() as stage:
            payload = extract(args.archive, Path(stage) / 'payload')
            result = inspect(payload, RELEASE_FILES)
            subprocess.run(['php', str(PACKAGE / 'sign.php'), '--bundle', str(payload), '--trust', str(args.trust), '--verify'], check=True)
    print(json.dumps(result, sort_keys=True))


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, subprocess.CalledProcessError, KeyError) as error:
        raise SystemExit(str(error))

#!/usr/bin/env python3
"""Promote fixed assets; existing public assets can only be verified, never replaced."""
import argparse
import json
from pathlib import Path
import subprocess
import tempfile
from release import verify_candidate_evidence
from bootstrap import REPOSITORY, COMMIT, VERSION, verify_tag, verify_attestation, digest, trust_document, fetch


def version_key(value):
    base, _, suffix = value.partition('-')
    identifiers = tuple((0, int(part)) if part.isdigit() else (1, part) for part in suffix.split('.')) if suffix else ()
    return (*map(int, base.split('.')), not bool(suffix), identifiers)


def validate_progress(candidate, previous):
    if type(candidate.get('release_sequence')) is not int or candidate['release_sequence'] < 1 or not VERSION.fullmatch(candidate.get('version', '')):
        raise ValueError('Invalid candidate release identity')
    for item in previous:
        if type(item.get('release_sequence')) is not int or not VERSION.fullmatch(item.get('version', '')):
            raise ValueError('Published release identity is invalid')
        if candidate['release_sequence'] <= item['release_sequence'] or version_key(candidate['version']) <= version_key(item['version']):
            raise ValueError('New CLI release must increase both version and release sequence')


def listed_releases():
    return json.loads(subprocess.check_output(['gh', 'api', '--paginate', '--slurp', f'repos/{REPOSITORY}/releases?per_page=100']))


def validate_promotion(args, releases):
    if args.tag.startswith('cli-trust-v'):
        document = trust_document(args.directory / 'trust.json')
        if document['version'] != int(args.tag[len('cli-trust-v'):]):
            raise ValueError('Trust version differs from release tag')
        for page in releases:
            for item in page:
                if not item['draft'] and item['tag_name'].startswith('cli-trust-v'):
                    previous = item['tag_name'][len('cli-trust-v'):]
                    if not previous.isdigit() or int(previous) >= document['version']:
                        raise ValueError('Trust release must advance the published root version')
    else:
        candidate = json.loads((args.directory / 'release.json').read_text())
        if candidate.get('version') != args.tag[5:] or candidate.get('source_commit') != args.source_commit or candidate.get('archive_sha256') != digest(args.directory / f'geoflow-cli-{args.tag[5:]}.tar'):
            raise ValueError('Publication identity does not match candidate bytes')
        verify_candidate_evidence(candidate)
        previous = []
        for page in releases:
            for item in page:
                if item['draft'] or not item['tag_name'].startswith('cli-v'):
                    continue
                with tempfile.TemporaryDirectory() as stage:
                    subprocess.run(['gh', 'release', 'download', item['tag_name'], '--repo', REPOSITORY, '--pattern', 'release.json', '--dir', stage], check=True)
                    metadata = Path(stage) / 'release.json'
                    record = json.loads(metadata.read_text())
                    if not COMMIT.fullmatch(record.get('source_commit', '')) or record.get('version') != item['tag_name'][5:]:
                        raise ValueError('Published CLI release has invalid provenance')
                    verify_tag(item['tag_name'], record['source_commit'])
                    verify_attestation(metadata, 'cli-sign.yml', record['source_commit'])
                    previous.append(record)
        validate_progress(candidate, previous)



def main():
    parser = argparse.ArgumentParser(description=__doc__, allow_abbrev=False)
    parser.add_argument('--tag', required=True)
    parser.add_argument('--source-commit', required=True)
    parser.add_argument('--directory', required=True, type=Path)
    args = parser.parse_args()
    if not COMMIT.fullmatch(args.source_commit):
        raise ValueError('Immutable source required')
    if args.tag.startswith('cli-trust-v'):
        if not args.tag[len('cli-trust-v'):].isdigit():
            raise ValueError('Invalid trust release tag')
        names = {'trust.json'}
    elif args.tag.startswith('cli-v') and VERSION.fullmatch(args.tag[5:]):
        names = {f'geoflow-cli-{args.tag[5:]}.tar', 'bootstrap.py', 'release.json'}
    else:
        raise ValueError('Only independently versioned CLI release tags are permitted')
    if {path.name for path in args.directory.iterdir()} != names or any(path.is_symlink() or not path.is_file() for path in args.directory.iterdir()):
        raise ValueError('Publication asset set mismatch')
    # Listing succeeds or fails explicitly; connection/permission failures never mean absent.
    releases = listed_releases()
    existing = next((item for page in releases for item in page if item['tag_name'] == args.tag), None)
    refs = json.loads(subprocess.check_output(['gh', 'api', f'repos/{REPOSITORY}/git/matching-refs/tags/{args.tag}']))
    if any(item['ref'] == 'refs/tags/' + args.tag for item in refs):
        verify_tag(args.tag, args.source_commit)
    if existing is None:
        validate_promotion(args, releases)
        subprocess.run(['gh', 'release', 'create', args.tag, *[str(args.directory / name) for name in sorted(names)], '--repo', REPOSITORY, '--target', args.source_commit, '--draft', '--latest=false', '--title', args.tag, '--notes', 'Immutable CLI distribution. Verify the official workflow attestation and pinned source commit before installation.'], check=True)
    with tempfile.TemporaryDirectory() as downloaded:
        subprocess.run(['gh', 'release', 'download', args.tag, '--repo', REPOSITORY, '--dir', downloaded], check=True)
        if {p.name for p in Path(downloaded).iterdir()} != names:
            raise ValueError('Published asset set differs from approved candidate')
        for name in names:
            if digest(Path(downloaded) / name) != digest(args.directory / name):
                raise ValueError('Existing release differs from approved bytes; publish a new version')
    if existing is None or existing['draft']:
        # Draft retries must pass today's public high-water, even if they passed
        # when the draft was created. Publication workflows share a concurrency lock.
        live = listed_releases()
        current = next((item for page in live for item in page if item['tag_name'] == args.tag), None)
        if current is None or current['draft']:
            validate_promotion(args, live)
            subprocess.run(['gh', 'release', 'edit', args.tag, '--repo', REPOSITORY, '--draft=false', '--latest=false'], check=True)
    try:
        verify_tag(args.tag, args.source_commit)
        # Use the anonymous public download route after promotion, with a fresh
        # cache, so authenticated draft reads cannot satisfy the release gate.
        with tempfile.TemporaryDirectory() as public:
            for name in sorted(names):
                expected = digest(args.directory / name)
                target = Path(public) / name
                fetch(f'https://github.com/{REPOSITORY}/releases/download/{args.tag}/{name}', target, expected)
                if digest(target) != expected:
                    raise ValueError('Public asset bytes differ from approved candidate')
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        raise ValueError('Release is already public; public verification failed; retry the same immutable release: ' + str(error)) from error
    print(json.dumps({'tag': args.tag, 'source_commit': args.source_commit, 'latest': False, 'verified_assets': sorted(names)}))


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        raise SystemExit(str(error))

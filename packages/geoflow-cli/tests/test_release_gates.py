import contextlib
import datetime
import fcntl
import hashlib
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import textwrap
import unittest
from unittest import mock

PACKAGE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(PACKAGE))
import bootstrap
import publish
import release


class ReleaseGateTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.source = 'a' * 40
        self.now = datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0)

    def tearDown(self):
        self.temp.cleanup()

    def trust(self, version=1):
        return {'schema_version': 1, 'version': version, 'expires_at': (self.now + datetime.timedelta(days=90)).strftime('%Y-%m-%dT%H:%M:%SZ'), 'keys': {'test': {'public_key': '//////////////////////////////////////////8=', 'status': 'active'}}}

    def rotate(self, document, bindir):
        archive = self.root / ('trust-' + str(document['version']) + '.json')
        archive.write_text(json.dumps(document))
        args = ['bootstrap.py', 'trust', '--version', str(document['version']), '--source-commit', self.source, '--sha256', bootstrap.digest(archive), '--bin-dir', str(bindir), '--cache-dir', str(self.root / 'cache')]
        with mock.patch.object(sys, 'argv', args), mock.patch.object(bootstrap, 'verify_tag'), mock.patch.object(bootstrap, 'fetch', return_value=archive), mock.patch.object(bootstrap, 'verify_attestation'), contextlib.redirect_stdout(io.StringIO()):
            bootstrap.main()

    def bundle(self, version, sequence, key, trust):
        bundle = self.root / ('bundle-' + version)
        bundle.mkdir()
        phar = ('<?php echo "' + version + '";').encode()
        (bundle / 'geoflow.phar').write_bytes(phar)
        manifest = {'schema_version': 2, 'version': version, 'release_sequence': sequence, 'source_commit': self.source, 'protocol_version': '1.0', 'file': 'geoflow.phar', 'size': len(phar), 'sha256': hashlib.sha256(phar).hexdigest()}
        (bundle / 'manifest.json').write_text(json.dumps(manifest))
        subprocess.run(['php', str(PACKAGE / 'sign.php'), '--bundle', str(bundle), '--key-file', str(key), '--key-id', 'test', '--trust', str(trust)], check=True, capture_output=True)
        return bundle

    def test_independent_root_rotation_blocks_archived_old_root_in_real_installer(self):
        key, old_path, bindir = self.root / 'key', self.root / 'old.json', self.root / 'bin'
        public = subprocess.check_output(['php', '-r', '$p=sodium_crypto_sign_keypair(); file_put_contents($argv[1],base64_encode(sodium_crypto_sign_secretkey($p))); echo base64_encode(sodium_crypto_sign_publickey($p));', str(key)], text=True)
        old = self.trust()
        old['keys']['test']['public_key'] = public
        old_path.write_text(json.dumps(old))
        first = self.bundle('1.0.0', 1, key, old_path)
        installed = subprocess.run(['php', str(PACKAGE / 'install.php'), '--bundle', str(first), '--trusted-keys', str(old_path), '--bin-dir', str(bindir)], capture_output=True, text=True)
        self.assertEqual(0, installed.returncode, installed.stderr)
        (bindir / '.geoflow-trust.json').write_text(json.dumps(old))
        updated = json.loads(json.dumps(old))
        updated['version'] = 2
        updated['keys']['test']['status'] = 'revoked'
        self.rotate(updated, bindir)
        second = self.bundle('1.1.0', 2, key, old_path)
        attempt = subprocess.run(['php', str(PACKAGE / 'install.php'), '--bundle', str(second), '--trusted-keys', str(old_path), '--bin-dir', str(bindir), '--update'], capture_output=True, text=True)
        self.assertNotEqual(0, attempt.returncode, 'Archived root must never bypass independent revocation')
        self.assertIn('Trust rollback', attempt.stderr)
        self.assertEqual('1.0.0', subprocess.check_output(['php', str(bindir / 'geoflow')], text=True))
        self.assertEqual(2, json.loads((bindir / '.geoflow-trust-state.json').read_text())['version'])

    def test_rotation_holds_install_lock_and_persists_highwater_before_root(self):
        bindir = self.root / 'bin'
        bindir.mkdir()
        (bindir / '.geoflow-trust.json').write_text(json.dumps(self.trust()))
        original = bootstrap.atomic
        def fail_root(path, content):
            if path.name == '.geoflow-trust.json':
                with open(bindir / '.geoflow-install.lock', 'a') as contender:
                    with self.assertRaises(BlockingIOError):
                        fcntl.flock(contender, fcntl.LOCK_EX | fcntl.LOCK_NB)
                self.assertEqual(2, json.loads((bindir / '.geoflow-trust-state.json').read_text())['version'])
                raise OSError('injected root activation failure')
            return original(path, content)
        with mock.patch.object(bootstrap, 'atomic', side_effect=fail_root), self.assertRaisesRegex(OSError, 'activation failure'):
            self.rotate(self.trust(2), bindir)
        # The same official root resumes; failed writes never relax the durable floor.
        self.rotate(self.trust(2), bindir)
        with self.assertRaisesRegex(ValueError, 'rollback|downgrade'):
            self.rotate(self.trust(1), bindir)
        expected = subprocess.check_output(['php', '-r', 'echo hash("sha256",json_encode(json_decode(file_get_contents($argv[1]),true)));', str(bindir / '.geoflow-trust.json')], text=True)
        self.assertEqual(expected, json.loads((bindir / '.geoflow-trust-state.json').read_text())['sha256'])

    def publisher(self, releases, public_failure=False):
        directory = self.root / 'publication'
        directory.mkdir(exist_ok=True)
        (directory / 'trust.json').write_text(json.dumps(self.trust(2)))
        args = ['publish.py', '--tag', 'cli-trust-v2', '--source-commit', self.source, '--directory', str(directory)]
        events = []
        def api(command, **kwargs):
            if 'releases?per_page=100' in command[-1]:
                events.append('list')
                return json.dumps([releases]).encode()
            return b'[]'
        def run(command, **kwargs):
            if command[1:3] == ['release', 'download']:
                output = Path(command[command.index('--dir') + 1])
                (output / 'trust.json').write_bytes((directory / 'trust.json').read_bytes())
                events.append('draft-read')
            elif command[1:3] == ['release', 'edit']:
                events.append('publish')
        def public_fetch(url, destination, expected):
            events.append('public-read')
            self.assertIn('/releases/download/cli-trust-v2/trust.json', url)
            self.assertEqual(bootstrap.digest(directory / 'trust.json'), expected)
            if public_failure:
                raise OSError('public asset unavailable')
            Path(destination).write_bytes((directory / 'trust.json').read_bytes())
        patches = [mock.patch.object(sys, 'argv', args), mock.patch.object(subprocess, 'check_output', side_effect=api), mock.patch.object(subprocess, 'run', side_effect=run), mock.patch.object(publish, 'verify_tag'), mock.patch.object(bootstrap, 'fetch', side_effect=public_fetch)]
        # publish imports may bind fetch directly; cover that seam when present.
        if hasattr(publish, 'fetch'):
            patches.append(mock.patch.object(publish, 'fetch', side_effect=public_fetch))
        return events, patches

    def test_retry_old_draft_cannot_publish_after_newer_root_is_public(self):
        events, patches = self.publisher([{'tag_name': 'cli-trust-v2', 'draft': True}, {'tag_name': 'cli-trust-v3', 'draft': False}])
        with contextlib.ExitStack() as stack:
            for patch in patches:
                stack.enter_context(patch)
            with self.assertRaisesRegex(ValueError, 'advance'):
                publish.main()
        self.assertNotIn('publish', events)

    def test_publication_success_requires_public_download_after_draft_promotion(self):
        events, patches = self.publisher([{'tag_name': 'cli-trust-v2', 'draft': True}])
        with contextlib.ExitStack() as stack, contextlib.redirect_stdout(io.StringIO()) as output:
            for patch in patches:
                stack.enter_context(patch)
            publish.main()
        self.assertIn('public-read', events)
        self.assertLess(events.index('publish'), events.index('public-read'))
        self.assertEqual(['trust.json'], json.loads(output.getvalue())['verified_assets'])
        self.assertGreaterEqual(events.count('list'), 2, 'Recheck published high-water immediately before promotion')

    def test_public_readback_failure_is_explicit_and_public_retry_is_idempotent(self):
        events, patches = self.publisher([{'tag_name': 'cli-trust-v2', 'draft': True}], public_failure=True)
        with contextlib.ExitStack() as stack, contextlib.redirect_stdout(io.StringIO()) as output:
            for patch in patches:
                stack.enter_context(patch)
            with self.assertRaisesRegex(ValueError, 'public.*retry'):
                publish.main()
        self.assertIn('publish', events)
        self.assertEqual('', output.getvalue())
        # A later retry verifies already public historical bytes, even after v3 exists.
        events, patches = self.publisher([{'tag_name': 'cli-trust-v2', 'draft': False}, {'tag_name': 'cli-trust-v3', 'draft': False}])
        with contextlib.ExitStack() as stack, contextlib.redirect_stdout(io.StringIO()):
            for patch in patches:
                stack.enter_context(patch)
            publish.main()
        self.assertNotIn('publish', events)
        self.assertIn('public-read', events)

    def test_candidate_age_is_not_extended_by_signing_or_signed_artifact_retention(self):
        evidence = {'schema_version': 1, 'run_id': 10, 'artifact_id': 20, 'source_commit': self.source, 'candidate_sha256': 'b' * 64, 'created_at': '2026-01-01T00:00:00Z', 'expires_at': '2026-01-31T00:00:00Z'}
        at_sign = datetime.datetime(2026, 1, 30, tzinfo=datetime.timezone.utc)
        at_publish = datetime.datetime(2026, 2, 10, tzinfo=datetime.timezone.utc)
        release.validate_candidate_lifetime(evidence, 'b' * 64, self.source, now=at_sign)
        with self.assertRaisesRegex(ValueError, 'expired'):
            release.validate_candidate_lifetime(evidence, 'b' * 64, self.source, now=at_publish)
        with self.assertRaisesRegex(ValueError, 'identity'):
            release.validate_candidate_lifetime(evidence, 'c' * 64, self.source, now=at_sign)

    def test_live_candidate_artifact_must_match_original_attested_lifetime(self):
        created = self.now - datetime.timedelta(days=2)
        evidence = {'schema_version': 1, 'run_id': 10, 'artifact_id': 20, 'source_commit': self.source, 'candidate_sha256': 'b' * 64, 'created_at': created.strftime('%Y-%m-%dT%H:%M:%SZ'), 'expires_at': (created + datetime.timedelta(days=30)).strftime('%Y-%m-%dT%H:%M:%SZ')}
        run = {'id': 10, 'conclusion': 'success', 'event': 'workflow_dispatch', 'head_branch': 'main', 'head_sha': self.source, 'path': '.github/workflows/cli-candidate.yml'}
        artifact = {'id': 20, 'name': 'cli-candidate-10', 'expired': False, 'created_at': evidence['created_at'], 'expires_at': evidence['expires_at'], 'workflow_run': {'id': 10, 'head_sha': self.source}}
        record = {'candidate': evidence, 'candidate_sha256': 'b' * 64, 'source_commit': self.source}
        with mock.patch.object(subprocess, 'check_output', side_effect=[json.dumps(run), json.dumps([{'artifacts': [artifact]}])]):
            self.assertEqual(evidence, release.candidate_lifetime(10, 'b' * 64, self.source))
        with mock.patch.object(subprocess, 'check_output', side_effect=[json.dumps(run), json.dumps(artifact)]):
            self.assertEqual(evidence, release.verify_candidate_evidence(record))
        for changed in [artifact | {'expired': True}, artifact | {'id': 21}, artifact | {'expires_at': (created + datetime.timedelta(days=40)).strftime('%Y-%m-%dT%H:%M:%SZ')}]:
            with self.subTest(changed=changed), mock.patch.object(subprocess, 'check_output', side_effect=[json.dumps(run), json.dumps(changed)]), self.assertRaisesRegex(ValueError, 'expired|identity|changed'):
                release.verify_candidate_evidence(record)

    def test_expired_original_candidate_blocks_promotion_with_fresh_signed_assets(self):
        directory = self.root / 'cli'
        directory.mkdir()
        (directory / 'geoflow-cli-1.0.0.tar').write_bytes(b'fresh-signed-fixture')
        (directory / 'bootstrap.py').write_bytes(b'bootstrap-fixture')
        evidence = {'schema_version': 1, 'run_id': 10, 'artifact_id': 20, 'source_commit': self.source, 'candidate_sha256': 'b' * 64, 'created_at': (self.now - datetime.timedelta(days=40)).strftime('%Y-%m-%dT%H:%M:%SZ'), 'expires_at': (self.now - datetime.timedelta(days=10)).strftime('%Y-%m-%dT%H:%M:%SZ')}
        record = {'version': '1.0.0', 'release_sequence': 1, 'source_commit': self.source, 'archive_sha256': bootstrap.digest(directory / 'geoflow-cli-1.0.0.tar'), 'candidate_sha256': 'b' * 64, 'candidate': evidence}
        (directory / 'release.json').write_text(json.dumps(record))
        args = ['publish.py', '--tag', 'cli-v1.0.0', '--source-commit', self.source, '--directory', str(directory)]
        public = [[{'tag_name': 'cli-v1.0.0', 'draft': True}]]
        def run(command, **kwargs):
            if command[1:3] == ['release', 'download']:
                output = Path(command[command.index('--dir') + 1])
                for source in directory.iterdir():
                    (output / source.name).write_bytes(source.read_bytes())
        with mock.patch.object(sys, 'argv', args), mock.patch.object(subprocess, 'check_output', side_effect=[json.dumps(public), '[]', json.dumps(public)]), mock.patch.object(subprocess, 'run', side_effect=run) as calls:
            with self.assertRaisesRegex(ValueError, 'expired'):
                publish.main()
        self.assertFalse(any(call.args[0][1:3] == ['release', 'edit'] for call in calls.call_args_list))

    def expired_release_workflow(self, draft):
        """Execute the real workflow shell with isolated GitHub/download fixtures."""
        directory, commands, runner = self.root / 'signed-assets', self.root / 'commands', self.root / 'runner'
        for path in (directory, commands, runner):
            path.mkdir()
        (directory / 'geoflow-cli-1.0.0.tar').write_bytes(b'previously-signed-fixture')
        (directory / 'bootstrap.py').write_bytes(b'bootstrap-fixture')
        evidence = {'schema_version': 1, 'run_id': 10, 'artifact_id': 20, 'source_commit': self.source, 'candidate_sha256': 'b' * 64, 'created_at': (self.now - datetime.timedelta(days=31)).strftime('%Y-%m-%dT%H:%M:%SZ'), 'expires_at': (self.now - datetime.timedelta(days=1)).strftime('%Y-%m-%dT%H:%M:%SZ')}
        record = {'version': '1.0.0', 'release_sequence': 1, 'source_commit': self.source, 'archive_sha256': bootstrap.digest(directory / 'geoflow-cli-1.0.0.tar'), 'candidate_sha256': 'b' * 64, 'candidate': evidence}
        (directory / 'release.json').write_text(json.dumps(record))
        gh = commands / 'gh'
        gh.write_text('#!' + sys.executable + '\n' + textwrap.dedent("""\
            import json, os, sys, shutil
            from pathlib import Path
            args = sys.argv[1:]
            with open(os.environ['TEST_EVENTS'], 'a') as log:
                log.write(json.dumps(args) + '\\n')
            if args[:2] == ['attestation', 'verify']:
                pass
            elif args[:2] in [['run', 'download'], ['release', 'download']]:
                target = Path(args[args.index('--dir') + 1])
                target.mkdir(exist_ok=True)
                for item in Path(os.environ['TEST_ASSETS']).iterdir():
                    shutil.copyfile(item, target / item.name)
            elif args[0] == 'api':
                endpoint = args[-1]
                if endpoint.endswith('/actions/runs/30'):
                    print(json.dumps({'conclusion': 'success', 'event': 'workflow_dispatch', 'head_branch': 'main', 'head_sha': os.environ['GITHUB_SHA'], 'path': '.github/workflows/cli-sign.yml'}))
                elif endpoint.endswith('/releases?per_page=100'):
                    print(json.dumps([[{'tag_name': 'cli-v1.0.0', 'draft': os.environ['TEST_DRAFT'] == '1'}]]))
                elif '/git/matching-refs/' in endpoint:
                    print('[]')
                elif '/git/ref/tags/' in endpoint:
                    print(json.dumps({'object': {'type': 'commit', 'sha': os.environ['GITHUB_SHA']}}))
                else:
                    raise SystemExit('Unexpected API fixture: ' + endpoint)
            else:
                raise SystemExit('Unexpected GitHub fixture: ' + str(args))
            """))
        gh.chmod(0o700)
        # Only the remote asset read seam is replaced; actual workflow routing,
        # shell gates, candidate expiry and publisher code run in subprocesses.
        (commands / 'sitecustomize.py').write_text(textwrap.dedent("""\
            import os, json
            from pathlib import Path
            import bootstrap
            def fixture_fetch(url, destination, expected):
                with open(os.environ['TEST_EVENTS'], 'a') as log:
                    log.write(json.dumps(['public-fetch', url]) + '\\n')
                source = Path(os.environ['TEST_ASSETS']) / url.rsplit('/', 1)[-1]
                if bootstrap.digest(source) != expected:
                    raise ValueError('Fixture digest mismatch')
                Path(destination).write_bytes(source.read_bytes())
                return destination
            bootstrap.fetch = fixture_fetch
            """))
        workflow = (PACKAGE.parents[1] / '.github/workflows/cli-release.yml').read_text()
        script = textwrap.dedent(workflow.split('        run: |\n', 1)[1])
        events = self.root / 'events.jsonl'
        env = dict(os.environ, PATH=str(commands) + os.pathsep + os.environ['PATH'], PYTHONPATH=str(commands) + os.pathsep + str(PACKAGE), PYTHONDONTWRITEBYTECODE='1', GH_TOKEN='test-only-token', GITHUB_REPOSITORY=bootstrap.REPOSITORY, GITHUB_SHA=self.source, RUN_ID='30', EXPECTED=record['archive_sha256'], RUNNER_TEMP=str(runner), TEST_ASSETS=str(directory), TEST_EVENTS=str(events), TEST_DRAFT='1' if draft else '0')
        result = subprocess.run(['bash', '-c', script], cwd=PACKAGE.parents[1], env=env, capture_output=True, text=True)
        self.assertTrue(events.exists(), result.stderr)
        return result, [json.loads(line) for line in events.read_text().splitlines()]

    def test_release_workflow_day31_retries_public_exact_bytes_after_candidate_expiry(self):
        result, events = self.expired_release_workflow(draft=False)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(3, sum(event[0] == 'public-fetch' for event in events))
        self.assertEqual(3, sum(event[:2] == ['attestation', 'verify'] for event in events))
        self.assertFalse(any(event[:2] == ['release', 'edit'] for event in events))
        self.assertEqual(3, len(json.loads(result.stdout)['verified_assets']))

    def test_release_workflow_day31_still_rejects_expired_draft(self):
        result, events = self.expired_release_workflow(draft=True)
        self.assertNotEqual(0, result.returncode)
        self.assertIn('expired', result.stderr)
        self.assertFalse(any(event[:2] == ['release', 'edit'] or event[0] == 'public-fetch' for event in events))


if __name__ == '__main__':
    unittest.main()

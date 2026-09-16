import datetime
import hashlib
import io
import json
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest import mock

PACKAGE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(PACKAGE))
import bootstrap
import release
import publish


class Response(io.BytesIO):
    def __init__(self, content, status=200, headers=None):
        super().__init__(content)
        self.status = status
        self.headers = headers or {'Content-Length': str(len(content))}


class DistributionTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.directory = Path(self.temp.name)

    def tearDown(self):
        self.temp.cleanup()

    def payload(self):
        return {name: (b'<?php echo "fixture";' if name.endswith('.phar') else b'fixture') for name in bootstrap.RELEASE_FILES}

    def tar(self, files=None):
        archive = self.directory / ('archive-' + str(len(list(self.directory.iterdir()))) + '.tar')
        release.archive_files(files or self.payload(), archive)
        return archive

    def test_fixed_archive_is_extracted_without_executing_payload(self):
        archive = self.tar()
        payload = bootstrap.extract(archive, self.directory / 'payload')
        self.assertEqual(bootstrap.RELEASE_FILES, {p.name for p in payload.iterdir()})
        self.assertEqual(0o600, (payload / 'install.php').stat().st_mode & 0o777)

    def test_extra_traversal_symlink_duplicate_and_executable_members_are_rejected_before_writes(self):
        for attack in ['extra', 'traversal', 'symlink', 'duplicate', 'executable']:
            with self.subTest(attack=attack):
                archive = self.directory / (attack + '.tar')
                with tarfile.open(archive, 'w', format=tarfile.USTAR_FORMAT) as stream:
                    for name, content in self.payload().items():
                        member = tarfile.TarInfo(name)
                        member.size = len(content)
                        member.mode = 0o644
                        if name == 'install.php' and attack == 'executable':
                            member.mode = 0o755
                        if name == 'install.php' and attack == 'symlink':
                            member.type, member.linkname, member.size = tarfile.SYMTYPE, '/tmp/evil', 0
                        stream.addfile(member, io.BytesIO(content) if member.isfile() else None)
                    if attack in ['extra', 'traversal', 'duplicate']:
                        member = tarfile.TarInfo({'extra': 'evil.php', 'traversal': '../evil', 'duplicate': 'install.php'}[attack])
                        member.size = 1
                        stream.addfile(member, io.BytesIO(b'x'))
                output = self.directory / ('out-' + attack)
                with self.assertRaises(ValueError):
                    bootstrap.extract(archive, output)
                self.assertFalse(output.exists())

    def test_range_resume_checks_identity_and_full_digest(self):
        data = b'0123456789'
        destination = self.directory / 'asset'
        expected = hashlib.sha256(data).hexdigest()
        url = 'https://github.com/yaojingang/GEOFlow/releases/download/cli-v1.0.0/archive.tar'
        Path(str(destination) + '.part').write_bytes(data[:4])
        Path(str(destination) + '.download.json').write_text(json.dumps({'url': url, 'sha256': expected, 'etag': 'fixed'}))
        def open_request(request, timeout):
            self.assertEqual('bytes=4-', request.headers['Range'])
            self.assertEqual('fixed', request.headers['If-range'])
            self.assertNotIn('Authorization', request.headers)
            return Response(data[4:], 206, {'Content-Range': 'bytes 4-9/10', 'ETag': 'fixed'})
        bootstrap.fetch(url, destination, expected, open_request)
        self.assertEqual(data, destination.read_bytes())

    def test_server_ignoring_range_restarts_instead_of_appending(self):
        data = b'complete'
        expected = hashlib.sha256(data).hexdigest()
        destination = self.directory / 'asset'
        url = 'https://github.com/a'
        Path(str(destination) + '.part').write_bytes(b'old partial')
        Path(str(destination) + '.download.json').write_text(json.dumps({'url': url, 'sha256': expected}))
        bootstrap.fetch(url, destination, expected, lambda *args, **kwargs: Response(data))
        self.assertEqual(data, destination.read_bytes())

    def test_changed_resume_etag_restarts_and_wrong_hash_never_activates(self):
        data = b'complete'
        destination = self.directory / 'asset'
        url, expected = 'https://github.com/a', hashlib.sha256(data).hexdigest()
        Path(str(destination) + '.part').write_bytes(data[:2])
        Path(str(destination) + '.download.json').write_text(json.dumps({'url': url, 'sha256': expected, 'etag': 'old'}))
        responses = iter([Response(data[2:], 206, {'Content-Range': 'bytes 2-7/8', 'ETag': 'new'}), Response(data)])
        bootstrap.fetch(url, destination, expected, lambda *args, **kwargs: next(responses))
        self.assertEqual(data, destination.read_bytes())
        destination.unlink()
        with self.assertRaisesRegex(ValueError, 'digest'):
            bootstrap.fetch(url, destination, expected, lambda *args, **kwargs: Response(b'tampered'))
        self.assertFalse(destination.exists())

    def test_redirects_reject_credentials_http_and_unapproved_origins(self):
        for url in ['http://github.com/a', 'https://evil.example/a', 'https://token@github.com/a', 'https://github.com:8443/a']:
            with self.subTest(url=url), self.assertRaises(ValueError):
                bootstrap.allowed_url(url)
        bootstrap.allowed_url('https://release-assets.githubusercontent.com/asset')

    def test_failed_attestation_prevents_installer_execution(self):
        archive = self.tar()
        args = ['bootstrap.py', 'install', '--version', '1.0.0', '--source-commit', 'a' * 40, '--sha256', bootstrap.digest(archive), '--bin-dir', str(self.directory / 'bin'), '--cache-dir', str(self.directory / 'cache')]
        with mock.patch.object(sys, 'argv', args), mock.patch.object(bootstrap, 'verify_tag'), mock.patch.object(bootstrap, 'fetch', return_value=archive), mock.patch.object(bootstrap, 'verify_attestation', side_effect=ValueError('untrusted workflow')), mock.patch.object(subprocess, 'run') as run:
            with self.assertRaisesRegex(ValueError, 'untrusted'):
                bootstrap.main()
            run.assert_not_called()
        self.assertFalse((self.directory / 'bin/.geoflow-trust.json').exists())

    def test_attestation_is_constrained_to_exact_official_workflow_ref_and_source(self):
        with mock.patch.object(subprocess, 'run') as run:
            bootstrap.verify_attestation(self.directory / 'archive', 'cli-sign.yml', 'a' * 40)
        command = run.call_args.args[0]
        self.assertEqual('yaojingang/GEOFlow', command[command.index('--repo') + 1])
        self.assertEqual('yaojingang/GEOFlow/.github/workflows/cli-sign.yml', command[command.index('--signer-workflow') + 1])
        self.assertEqual('refs/heads/main', command[command.index('--source-ref') + 1])
        self.assertEqual('a' * 40, command[command.index('--source-digest') + 1])
        self.assertIn('--deny-self-hosted-runners', command)

    def test_candidate_signature_keeps_every_approved_byte_and_installs_without_source(self):
        trust_path, key_path = self.directory / 'trust.json', self.directory / 'key'
        code = '$p=sodium_crypto_sign_keypair(); file_put_contents($argv[1],base64_encode(sodium_crypto_sign_secretkey($p))); echo base64_encode(sodium_crypto_sign_publickey($p));'
        public = subprocess.check_output(['php', '-r', code, str(key_path)], text=True)
        trust_path.write_text(json.dumps({'schema_version': 1, 'version': 1, 'expires_at': (datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(days=1)).strftime('%Y-%m-%dT%H:%M:%SZ'), 'keys': {'test': {'public_key': public, 'status': 'active'}}}))
        files = {name: (PACKAGE / name).read_bytes() for name in bootstrap.INSTALLER}
        phar = b'<?php echo "source-free-fixture";'
        manifest = {'schema_version': 2, 'version': '0.4.0', 'source_commit': 'a' * 40, 'release_sequence': 1, 'protocol_version': '1.0', 'file': 'geoflow.phar', 'size': len(phar), 'sha256': hashlib.sha256(phar).hexdigest()}
        files.update({'geoflow.phar': phar, 'manifest.json': release.encode(manifest), 'LICENSE': b'fixture license'})
        provenance = {'schema_version': 1, 'version': '0.4.0', 'source_commit': 'a' * 40, 'release_sequence': 1, 'files': {name: hashlib.sha256(content).hexdigest() for name, content in files.items()}}
        files['provenance.json'] = release.encode(provenance)
        unsigned, signed = self.directory / 'candidate.tar', self.directory / 'release.tar'
        checksum = release.archive_files(files, unsigned)
        now = datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0)
        evidence = {'schema_version': 1, 'run_id': 10, 'artifact_id': 20, 'source_commit': 'a' * 40, 'candidate_sha256': checksum, 'created_at': now.strftime('%Y-%m-%dT%H:%M:%SZ'), 'expires_at': (now + datetime.timedelta(days=30)).strftime('%Y-%m-%dT%H:%M:%SZ')}
        result = release.sign(unsigned, checksum, key_path, 'test', trust_path, signed, evidence)
        self.assertEqual(evidence, result['candidate'])
        payload = bootstrap.extract(signed, self.directory / 'payload')
        for name, content in files.items():
            self.assertEqual(content, (payload / name).read_bytes())
        release.inspect(payload, bootstrap.RELEASE_FILES)
        installed = subprocess.run(['php', str(payload / 'install.php'), '--bundle', str(payload), '--trusted-keys', str(trust_path), '--bin-dir', str(self.directory / 'bin')], capture_output=True, text=True)
        self.assertEqual(0, installed.returncode, installed.stderr)
        self.assertEqual('source-free-fixture', subprocess.check_output(['php', str(self.directory / 'bin/geoflow')], text=True))
        self.assertEqual(checksum, result['candidate_sha256'])
        with self.assertRaisesRegex(ValueError, 'approved digest'):
            release.sign(unsigned, '0' * 64, key_path, 'test', trust_path, self.directory / 'bad.tar')

    def test_release_set_requires_all_four_exact_identities_and_protocol_ranges(self):
        entry = {'version': '1.0.0', 'commit': 'a' * 40, 'sha256': 'b' * 64, 'protocols': {'management': {'min': '1.0', 'max': '1.0'}}}
        document = {'schema_version': 1, 'components': {name: dict(entry) for name in ['core', 'updater', 'cli', 'skill']}}
        self.assertEqual(document, release.release_set(document))
        document['components']['skill']['sha256'] = '0' * 64
        with self.assertRaises(ValueError):
            release.release_set(document)
        del document['components']['skill']
        with self.assertRaises(ValueError):
            release.release_set(document)

    def test_publication_rejects_sequence_reuse_and_version_regression(self):
        previous = [{'version': '0.4.0-preview.1', 'release_sequence': 7}]
        publish.validate_progress({'version': '0.4.0', 'release_sequence': 8}, previous)
        for candidate in [{'version': '0.4.0', 'release_sequence': 7}, {'version': '0.3.9', 'release_sequence': 8}, {'version': '0.4.0-preview.1', 'release_sequence': 8}]:
            with self.subTest(candidate=candidate), self.assertRaises(ValueError):
                publish.validate_progress(candidate, previous)

    def test_candidates_require_exact_clean_committed_source(self):
        output = self.directory / 'candidate.tar'
        with mock.patch.object(subprocess, 'check_output', side_effect=['a' * 40 + '\n', ' M app/changed.php\n']):
            with self.assertRaisesRegex(ValueError, 'clean committed'):
                release.candidate(self.directory, 'a' * 40, 1, output)
        self.assertFalse(output.exists())

    def test_first_download_cut_short_keeps_partial_for_next_attempt(self):
        url, data = 'https://github.com/a', b'complete-data'
        destination = self.directory / 'asset'
        expected = hashlib.sha256(data).hexdigest()
        with self.assertRaisesRegex(ValueError, 'interrupted'):
            bootstrap.fetch(url, destination, expected, lambda *args, **kwargs: Response(data[:3], 200, {'Content-Length': str(len(data)), 'ETag': 'same'}))
        self.assertEqual(data[:3], Path(str(destination) + '.part').read_bytes())
        self.assertFalse(destination.exists())
        bootstrap.fetch(url, destination, expected, lambda *args, **kwargs: Response(data[3:], 206, {'Content-Range': f'bytes 3-{len(data) - 1}/{len(data)}', 'ETag': 'same'}))
        self.assertEqual(data, destination.read_bytes())

    def test_normal_update_keeps_existing_trust_even_when_packaged_root_has_expired(self):
        bindir = self.directory / 'bin'
        bindir.mkdir()
        root = {'schema_version': 1, 'version': 2, 'expires_at': (datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(days=1)).strftime('%Y-%m-%dT%H:%M:%SZ'), 'keys': {'test': {'public_key': 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'status': 'active'}}}
        trusted = bindir / '.geoflow-trust.json'
        original = json.dumps(root).encode()
        trusted.write_bytes(original)
        files = self.payload()
        files['manifest.json'] = json.dumps({'schema_version': 2, 'version': '1.0.0', 'source_commit': 'a' * 40}).encode()
        files['trust.json'] = json.dumps(root | {'version': 1, 'expires_at': '2020-01-01T00:00:00Z'}).encode()
        archive = self.tar(files)
        args = ['bootstrap.py', 'update', '--version', '1.0.0', '--source-commit', 'a' * 40, '--sha256', bootstrap.digest(archive), '--bin-dir', str(bindir), '--cache-dir', str(self.directory / 'cache')]
        with mock.patch.object(sys, 'argv', args), mock.patch.object(bootstrap, 'verify_tag'), mock.patch.object(bootstrap, 'fetch', return_value=archive), mock.patch.object(bootstrap, 'verify_attestation'), mock.patch.object(subprocess, 'run') as run:
            bootstrap.main()
        self.assertEqual(original, trusted.read_bytes())
        command = run.call_args.args[0]
        self.assertEqual(str(trusted.resolve()), command[command.index('--trusted-keys') + 1])
        self.assertIn('--update', command)


if __name__ == '__main__':
    unittest.main()

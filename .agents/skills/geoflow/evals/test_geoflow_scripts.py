#!/usr/bin/env python3

import errno
import json
import os
import pty
import re
import select
import stat
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from unittest import mock


SCRIPT_DIR = Path(__file__).resolve().parents[1] / "scripts"
sys.path.insert(0, str(SCRIPT_DIR))

import finalize_theme_edit_session  # noqa: E402
import prepare_theme_edit_session  # noqa: E402
import serve_preview  # noqa: E402
import build_sync_preview_report  # noqa: E402
import compare_default_vs_channel_frontend  # noqa: E402
import discover_themes  # noqa: E402
from channel_endpoint_safety import require_signed_get_request_protection, validate_live_channel_endpoint  # noqa: E402
from discover_frontend_surfaces import php_array_string_value, target_package_surface  # noqa: E402


class FrontendSurfaceDiscoveryTest(unittest.TestCase):
    def test_php_array_string_value_extracts_value(self) -> None:
        source = "return ['capability_version' => '1.2'];"
        self.assertEqual("1.2", php_array_string_value(source, "capability_version"))

    def test_php_array_string_value_handles_missing_key(self) -> None:
        self.assertEqual("", php_array_string_value("return [];", "capability_version"))

    def test_target_package_surface_reports_version_value(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            workspace = Path(temp_dir)
            builder = workspace / "app/Services/GeoFlow/DistributionTargetSitePackageBuilder.php"
            builder.parent.mkdir(parents=True)
            builder.write_text(
                "<?php\nreturn ['capability_version' => '2.4'];\n",
                encoding="utf-8",
            )

            surface = target_package_surface(workspace)

        self.assertEqual("2.4", surface["capability_version"])


class ChannelEndpointSafetyTest(unittest.TestCase):
    def test_live_endpoint_requires_https_for_non_loopback_host(self) -> None:
        report = {"channel": {"endpoint_url": "http://channel.example.test/geoflow"}}
        with self.assertRaises(SystemExit):
            validate_live_channel_endpoint(report)

    def test_live_endpoint_allows_https_and_loopback_http(self) -> None:
        self.assertEqual(
            "https://channel.example.test/geoflow",
            validate_live_channel_endpoint({"channel": {"endpoint_url": "https://channel.example.test/geoflow"}}),
        )
        self.assertEqual(
            "http://127.0.0.1:18080/geoflow",
            validate_live_channel_endpoint({"channel": {"endpoint_url": "http://127.0.0.1:18080/geoflow"}}),
        )

    def test_live_helpers_block_unsafe_endpoint_before_signed_request(self) -> None:
        cached = subprocess.CompletedProcess(
            args=[],
            returncode=0,
            stdout='{"channel":{"endpoint_url":"http://channel.example.test"}}',
            stderr="",
        )
        for module in (build_sync_preview_report, compare_default_vs_channel_frontend):
            with self.subTest(module=module.__name__), mock.patch.object(
                module.subprocess,
                "run",
                return_value=cached,
            ) as run:
                with self.assertRaises(SystemExit):
                    module.run_artisan_report(Path("/workspace"), "7", True)
                self.assertEqual(1, run.call_count)
                self.assertNotIn("--live-remote", run.call_args.args[0])

    def test_live_endpoint_requires_in_process_endpoint_and_redirect_protection(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            workspace = Path(temp_dir)
            client = workspace / "app/Services/GeoFlow/DistributionHttpClient.php"
            client.parent.mkdir(parents=True)
            client.write_text(
                "<?php\nprivate function signedGetJson() { return Http::get('/'); }\n"
                "private function decodeJson() {}\n",
                encoding="utf-8",
            )
            with self.assertRaises(SystemExit):
                require_signed_get_request_protection(workspace)

            client.write_text(
                "<?php\nprivate function signedGetJson() {\n"
                "  $request = Http::withoutRedirecting();\n"
                "  $endpoint = $this->endpoint($channel, $path);\n"
                "  return $request->get($endpoint);\n"
                "}\n"
                "private function decodeJson() {}\n",
                encoding="utf-8",
            )
            with self.assertRaises(SystemExit):
                require_signed_get_request_protection(workspace)

            client.write_text(
                "<?php\nprivate function signedGetJson() {\n"
                "  $request = Http::withoutRedirecting();\n"
                "  $endpoint = $this->endpoint($channel, $path);\n"
                "  $this->assertSafeSignedEndpoint($endpoint);\n"
                "  return $request->get($endpoint);\n"
                "}\n"
                "private function assertSafeSignedEndpoint($endpoint) {\n"
                "  $scheme = parse_url($endpoint, PHP_URL_SCHEME);\n"
                "  $host = parse_url($endpoint, PHP_URL_HOST);\n"
                "  if ($scheme !== 'https' && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {\n"
                "    throw new RuntimeException('unsafe endpoint');\n"
                "  }\n"
                "}\n"
                "private function decodeJson() {}\n",
                encoding="utf-8",
            )
            require_signed_get_request_protection(workspace)


class ThemePathSafetyTest(unittest.TestCase):
    def test_prepare_rejects_parent_directory_theme_id(self) -> None:
        with self.assertRaises(SystemExit):
            prepare_theme_edit_session.require_theme_id("../outside", "Base theme id")

    def test_finalize_rejects_absolute_theme_id(self) -> None:
        with self.assertRaises(SystemExit):
            finalize_theme_edit_session.require_theme_id("/tmp/outside", "Preview theme id")

    def test_prepare_rejects_theme_symlink_outside_root(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir, tempfile.TemporaryDirectory() as outside_dir:
            themes_root = Path(temp_dir) / "themes"
            themes_root.mkdir()
            (themes_root / "linked-theme").symlink_to(Path(outside_dir), target_is_directory=True)

            with self.assertRaises(SystemExit):
                prepare_theme_edit_session.bounded_theme_path(themes_root, "linked-theme", "Base theme id")

    def test_copy_helpers_reject_nested_symlinks(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir, tempfile.TemporaryDirectory() as outside_dir:
            theme_root = Path(temp_dir) / "theme"
            theme_root.mkdir()
            outside_file = Path(outside_dir) / "secret.txt"
            outside_file.write_text("secret", encoding="utf-8")
            (theme_root / "linked-secret.txt").symlink_to(outside_file)

            for reject in (prepare_theme_edit_session.reject_symlinks, finalize_theme_edit_session.reject_symlinks):
                with self.assertRaises(SystemExit):
                    reject(theme_root, "Theme")

    def test_workspace_helpers_reject_symlinked_root_components(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir, tempfile.TemporaryDirectory() as outside_dir:
            workspace = Path(temp_dir).resolve()
            (workspace / "public").symlink_to(Path(outside_dir), target_is_directory=True)
            candidate = workspace / "public" / "themes"

            for bound in (prepare_theme_edit_session.bounded_workspace_path, finalize_theme_edit_session.bounded_workspace_path):
                with self.assertRaises(SystemExit):
                    bound(workspace, candidate, "Public themes directory")


class ThemeEditTransactionTest(unittest.TestCase):
    @staticmethod
    def make_theme(root: Path, theme_id: str, marker: str) -> Path:
        theme = root / theme_id
        theme.mkdir(parents=True)
        (theme / "manifest.json").write_text(
            '{"name": "Theme", "asset_path": "/themes/' + marker + '/theme.css"}\n',
            encoding="utf-8",
        )
        (theme / "home.blade.php").write_text(
            '<link href="/themes/' + marker + '/theme.css">\n',
            encoding="utf-8",
        )
        return theme

    def test_replace_base_rewrites_preview_id_in_theme_files(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            workspace = Path(temp_dir)
            themes_root = workspace / "resources/views/theme"
            self.make_theme(themes_root, "base", "base")
            preview = self.make_theme(themes_root, "base-edit", "base-edit")
            (preview / "edit-session.json").write_text(
                '{"base_theme_id": "base", "preview_theme_id": "base-edit"}\n',
                encoding="utf-8",
            )

            finalize_theme_edit_session.replace_base(
                themes_root,
                workspace,
                "base-edit",
                "base",
                None,
                True,
            )

            rendered = (themes_root / "base/home.blade.php").read_text(encoding="utf-8")
            self.assertIn('/themes/base/theme.css', rendered)
            self.assertNotIn('/themes/base-edit/theme.css', rendered)

    def test_replace_base_restores_live_theme_when_commit_rename_fails(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            workspace = Path(temp_dir)
            themes_root = workspace / "resources/views/theme"
            base = self.make_theme(themes_root, "base", "base")
            preview = self.make_theme(themes_root, "base-edit", "base-edit")
            (preview / "edit-session.json").write_text(
                '{"base_theme_id": "base", "preview_theme_id": "base-edit"}\n',
                encoding="utf-8",
            )
            original_rename = Path.rename
            injected = False

            def fail_staged_commit(path: Path, target: Path) -> Path:
                nonlocal injected
                if (
                    not injected
                    and path.name.startswith("base__replace_stage__")
                    and Path(target).name == "base"
                ):
                    injected = True
                    raise OSError("injected rename failure")
                return original_rename(path, target)

            with mock.patch.object(Path, "rename", fail_staged_commit):
                with self.assertRaises(OSError):
                    finalize_theme_edit_session.replace_base(
                        themes_root,
                        workspace,
                        "base-edit",
                        "base",
                        None,
                        True,
                    )

            self.assertTrue(base.is_dir())
            self.assertIn('/themes/base/theme.css', (base / "home.blade.php").read_text(encoding="utf-8"))

    def test_prepare_removes_partial_preview_when_public_copy_fails(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            workspace = Path(temp_dir)
            themes_root = workspace / "resources/views/theme"
            self.make_theme(themes_root, "base", "base")
            public_base = workspace / "public/themes/base"
            public_base.mkdir(parents=True)
            (public_base / "theme.css").write_text("/* base */\n", encoding="utf-8")

            real_copytree = prepare_theme_edit_session.shutil.copytree
            copy_count = 0

            def fail_second_copy(source: Path, target: Path, *args, **kwargs):
                nonlocal copy_count
                copy_count += 1
                if copy_count == 2:
                    raise OSError("injected public copy failure")
                return real_copytree(source, target, *args, **kwargs)

            argv = [
                "prepare_theme_edit_session.py",
                str(workspace),
                "--base-theme",
                "base",
                "--new-theme-id",
                "preview",
            ]
            with mock.patch.object(sys, "argv", argv), mock.patch.object(
                prepare_theme_edit_session.shutil,
                "copytree",
                fail_second_copy,
            ):
                with self.assertRaises(OSError):
                    prepare_theme_edit_session.main()

            self.assertFalse((themes_root / "preview").exists())
            self.assertFalse((workspace / "public/themes/preview").exists())

    def test_internal_transaction_paths_support_max_length_theme_id(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            themes_root = Path(temp_dir)
            theme_id = "a" * 100
            for operation in ("replace_stage", "replace_rollback", "publish_rollback"):
                path = finalize_theme_edit_session.unique_theme_path(themes_root, theme_id, operation)
                self.assertLessEqual(len(path.name), 100)
                self.assertEqual(path, finalize_theme_edit_session.bounded_theme_path(themes_root, path.name, operation))

    def test_finalize_rejects_invalid_manifest_before_backup_or_live_change(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            workspace = Path(temp_dir)
            themes_root = workspace / "resources/views/theme"
            base = self.make_theme(themes_root, "base", "base")
            preview = self.make_theme(themes_root, "base-edit", "base-edit")
            (preview / "manifest.json").write_text("{invalid", encoding="utf-8")

            with self.assertRaises(SystemExit):
                finalize_theme_edit_session.replace_base(
                    themes_root,
                    workspace,
                    "base-edit",
                    "base",
                    None,
                    True,
                )

            self.assertTrue(base.is_dir())
            self.assertFalse((workspace / "storage/app/private/geoflow-theme-backups").exists())

    def test_tree_validation_rejects_named_pipes(self) -> None:
        if not hasattr(os, "mkfifo"):
            self.skipTest("named pipes are not supported on this platform")
        with tempfile.TemporaryDirectory() as temp_dir:
            theme = Path(temp_dir) / "theme"
            theme.mkdir()
            os.mkfifo(theme / "blocked.fifo")

            for reject in (prepare_theme_edit_session.reject_symlinks, finalize_theme_edit_session.reject_symlinks):
                with self.assertRaises(SystemExit):
                    reject(theme, "Theme")

    def test_finalize_lock_blocks_concurrent_finalizers(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            workspace = Path(temp_dir)
            lock = finalize_theme_edit_session.acquire_finalize_lock(workspace, True)
            try:
                with self.assertRaises(SystemExit):
                    finalize_theme_edit_session.acquire_finalize_lock(workspace, True)
            finally:
                finalize_theme_edit_session.release_finalize_lock(lock)

            second_lock = finalize_theme_edit_session.acquire_finalize_lock(workspace, True)
            finalize_theme_edit_session.release_finalize_lock(second_lock)

    def test_finalize_lock_is_exclusive_across_processes(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            workspace = Path(temp_dir)
            child_code = (
                "import sys\n"
                "from pathlib import Path\n"
                "import finalize_theme_edit_session as f\n"
                "lock = f.acquire_finalize_lock(Path(sys.argv[1]), True)\n"
                "print('ready', flush=True)\n"
                "sys.stdin.read(1)\n"
                "f.release_finalize_lock(lock)\n"
            )
            env = os.environ.copy()
            env.update({
                "PYTHONPATH": str(SCRIPT_DIR),
                "PYTHONDONTWRITEBYTECODE": "1",
            })
            process = subprocess.Popen(
                [sys.executable, "-B", "-c", child_code, str(workspace)],
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                env=env,
            )
            try:
                self.assertEqual("ready", process.stdout.readline().strip())
                with self.assertRaises(SystemExit):
                    finalize_theme_edit_session.acquire_finalize_lock(workspace, True)
                process.stdin.close()
                self.assertEqual(0, process.wait(timeout=5), process.stderr.read())
            finally:
                if process.poll() is None:
                    process.kill()
                    process.wait(timeout=5)
                for stream in (process.stdin, process.stdout, process.stderr):
                    if stream is not None and not stream.closed:
                        stream.close()


class PreflightHttpStatusTest(unittest.TestCase):
    @staticmethod
    def make_laravel_workspace(root: Path) -> Path:
        workspace = root / "workspace"
        (workspace / "routes").mkdir(parents=True)
        (workspace / "artisan").write_text("#!/usr/bin/env php\n", encoding="utf-8")
        (workspace / "routes/api.php").write_text("<?php\n", encoding="utf-8")
        return workspace

    def test_api_fallback_rejects_http_500_json_response(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            workspace = self.make_laravel_workspace(root)

            fake_bin = root / "bin"
            fake_bin.mkdir()
            fake_curl = fake_bin / "curl"
            fake_curl.write_text(
                """#!/usr/bin/env python3
import json
import os
import sys
from pathlib import Path

args = sys.argv[1:]
Path(os.environ["GEOFLOW_FAKE_CURL_ARGS"]).write_text(json.dumps(args), encoding="utf-8")
header_flag = "--dump-header" if "--dump-header" in args else "-D"
header_path = Path(args[args.index(header_flag) + 1])
header_path.write_bytes(b"HTTP/1.1 500 Fixture\\r\\n\\r\\n")
leaf_secret = os.environ["GEOFLOW_TEST_MESSAGE_SECRET"]
environment_token = os.environ["GEOFLOW_API_TOKEN"]
print(json.dumps({
    "message": (
        f"server error token={leaf_secret} password={leaf_secret} secret={leaf_secret} "
        f"api_key={leaf_secret} Authorization: Bearer {leaf_secret} current={environment_token}"
    ),
    "nested": [f"secret={leaf_secret}", {"note": f"api_key={leaf_secret}"}],
}))
""",
                encoding="utf-8",
            )
            fake_curl.chmod(0o755)

            env = os.environ.copy()
            curl_args_path = root / "curl-args.json"
            message_secret = "fixture-message-leaf-secret"
            environment_token = "fixture-current-environment-token"
            env.update({
                "PATH": f"{fake_bin}:{env['PATH']}",
                "GEOFLOW_BASE_URL": "https://geoflow.example.test",
                "GEOFLOW_API_TOKEN": environment_token,
                "GEOFLOW_FAKE_CURL_ARGS": str(curl_args_path),
                "GEOFLOW_TEST_MESSAGE_SECRET": message_secret,
            })
            completed = subprocess.run(
                ["bash", str(SCRIPT_DIR / "geoflow_preflight.sh"), str(workspace)],
                text=True,
                capture_output=True,
                env=env,
                check=False,
            )

            self.assertNotEqual(0, completed.returncode)
            self.assertIn("HTTP 500", completed.stderr)
            self.assertIn("[redacted]", completed.stderr)
            combined = completed.stdout + completed.stderr
            if message_secret in combined or environment_token in combined:
                self.fail("preflight printed a credential embedded in a JSON string leaf")
            curl_args = json.loads(curl_args_path.read_text(encoding="utf-8"))
            self.assertIn("--globoff", curl_args)

    def test_api_fallback_rejects_file_base_url_before_curl(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            workspace = self.make_laravel_workspace(root)
            local_payload = root / "payload.json"
            local_payload.write_text('{"secret":"local-file"}\n', encoding="utf-8")
            env = os.environ.copy()
            env.update({
                "GEOFLOW_BASE_URL": f"{local_payload.as_uri()}#",
                "GEOFLOW_API_TOKEN": "test-token",
            })

            completed = subprocess.run(
                ["bash", str(SCRIPT_DIR / "geoflow_preflight.sh"), str(workspace)],
                text=True,
                capture_output=True,
                env=env,
                check=False,
            )

            self.assertNotEqual(0, completed.returncode)
            self.assertIn("must be an http(s) URL", completed.stderr)
            self.assertNotIn("local-file", completed.stdout + completed.stderr)
            self.assertNotIn("unbound variable", completed.stderr)

    def test_api_fallback_rejects_brace_glob_before_curl(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            workspace = self.make_laravel_workspace(root)
            fake_bin = root / "bin"
            fake_bin.mkdir()
            curl_marker = root / "curl-invoked"
            fake_curl = fake_bin / "curl"
            fake_curl.write_text(
                "#!/usr/bin/env python3\n"
                "import os\n"
                "from pathlib import Path\n"
                'Path(os.environ["GEOFLOW_CURL_MARKER"]).touch()\n',
                encoding="utf-8",
            )
            fake_curl.chmod(0o755)
            env = os.environ.copy()
            env.update({
                "PATH": f"{fake_bin}:{env['PATH']}",
                "GEOFLOW_BASE_URL": "https://geoflow.example.test/{first,second}",
                "GEOFLOW_API_TOKEN": "fixture-token",
                "GEOFLOW_CURL_MARKER": str(curl_marker),
            })

            completed = subprocess.run(
                ["bash", str(SCRIPT_DIR / "geoflow_preflight.sh"), str(workspace)],
                text=True,
                capture_output=True,
                env=env,
                check=False,
            )

            self.assertNotEqual(0, completed.returncode)
            self.assertIn("curl glob characters", completed.stderr)
            self.assertFalse(curl_marker.exists(), "curl ran for a rejected brace-glob URL")

    def test_api_fallback_allows_bracketed_loopback_ipv6_once(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            workspace = self.make_laravel_workspace(root)
            fake_bin = root / "bin"
            fake_bin.mkdir()
            call_log = root / "curl-calls.jsonl"
            fake_curl = fake_bin / "curl"
            fake_curl.write_text(
                """#!/usr/bin/env python3
import json
import os
import sys
from pathlib import Path

args = sys.argv[1:]
with Path(os.environ["GEOFLOW_CURL_CALL_LOG"]).open("a", encoding="utf-8") as handle:
    handle.write(json.dumps(args) + "\\n")
header = Path(args[args.index("--dump-header") + 1])
header.write_bytes(b"HTTP/1.1 200 Fixture\\r\\n\\r\\n")
print('{"success":true,"data":{}}', end="")
""",
                encoding="utf-8",
            )
            fake_curl.chmod(0o755)
            env = os.environ.copy()
            env.update({
                "PATH": f"{fake_bin}:{env['PATH']}",
                "GEOFLOW_BASE_URL": "http://[::1]:18080",
                "GEOFLOW_API_TOKEN": "fixture-token",
                "GEOFLOW_CURL_CALL_LOG": str(call_log),
            })

            completed = subprocess.run(
                ["bash", str(SCRIPT_DIR / "geoflow_preflight.sh"), str(workspace)],
                text=True,
                capture_output=True,
                env=env,
                check=False,
            )

            self.assertEqual(0, completed.returncode, completed.stderr)
            calls = [json.loads(line) for line in call_log.read_text(encoding="utf-8").splitlines()]
            self.assertEqual(1, len(calls))
            self.assertIn("--globoff", calls[0])
            self.assertIn("http://[::1]:18080/api/v1/catalog", calls[0])


class PreflightStreamingLimitTest(unittest.TestCase):
    @staticmethod
    def make_fake_curl(root: Path) -> tuple[Path, Path]:
        fake_bin = root / "bin"
        fake_bin.mkdir()
        call_log = root / "curl-call.json"
        fake_curl = fake_bin / "curl"
        fake_curl.write_text(
            """#!/usr/bin/env python3
import json
import os
import sys
from pathlib import Path

args = sys.argv[1:]
Path(os.environ["GEOFLOW_FAKE_CURL_CALL"]).write_text(json.dumps(args), encoding="utf-8")
header_flag = "--dump-header" if "--dump-header" in args else "-D"
header_path = Path(args[args.index(header_flag) + 1])
header_path.write_bytes(
    b"HTTP/1.1 " + os.environ["GEOFLOW_FAKE_HTTP_STATUS"].encode() + b" Fixture\\r\\n\\r\\n"
)
body_text = os.environ.get("GEOFLOW_FAKE_BODY_TEXT")
body = body_text.encode() if body_text is not None else b"x" * int(os.environ["GEOFLOW_FAKE_BODY_BYTES"])
sys.stdout.buffer.write(body)
""",
            encoding="utf-8",
        )
        fake_curl.chmod(0o755)
        return fake_bin, call_log

    @staticmethod
    def make_laravel_workspace(root: Path) -> Path:
        workspace = root / "workspace"
        (workspace / "routes").mkdir(parents=True)
        (workspace / "artisan").write_text("#!/usr/bin/env php\n", encoding="utf-8")
        (workspace / "routes/api.php").write_text("<?php\n", encoding="utf-8")
        return workspace

    def test_preflight_streaming_limit_stops_curl_that_ignores_max_filesize(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            oversized = b"x" * (5 * 1024 * 1024 + 65536)
            fake_bin, call_log = self.make_fake_curl(root)
            workspace = self.make_laravel_workspace(root)
            env = os.environ.copy()
            env.update({
                "PATH": f"{fake_bin}:{env['PATH']}",
                "GEOFLOW_BASE_URL": "https://geoflow.example.test",
                "GEOFLOW_API_TOKEN": "fixture-token",
                "GEOFLOW_FAKE_CURL_CALL": str(call_log),
                "GEOFLOW_FAKE_HTTP_STATUS": "200",
                "GEOFLOW_FAKE_BODY_BYTES": str(len(oversized)),
            })

            completed = subprocess.run(
                [
                    "/bin/bash",
                    str(SCRIPT_DIR / "geoflow_preflight.sh"),
                    str(workspace),
                ],
                text=True,
                capture_output=True,
                env=env,
                check=False,
            )

            self.assertNotEqual(0, completed.returncode)
            self.assertIn("exceeded 5242880 bytes", completed.stderr)
            self.assertNotIn("x" * 1000, completed.stdout + completed.stderr)
            curl_args = json.loads(call_log.read_text(encoding="utf-8"))
            self.assertIn("--dump-header", curl_args)
            self.assertNotIn("--location", curl_args)


class PreflightCliDispatchTest(unittest.TestCase):
    @staticmethod
    def make_cli_workspace(root: Path, workspace_name: str = "workspace") -> tuple[Path, Path]:
        workspace = root / workspace_name
        cli = workspace / "bin/geoflow"
        cli.parent.mkdir(parents=True)
        cli.write_text(
            """#!/usr/bin/env python3
import json
import os
import sys
from pathlib import Path

args = sys.argv[1:]
log_path = Path(os.environ["GEOFLOW_FAKE_CLI_LOG"])
with log_path.open("a", encoding="utf-8") as handle:
    handle.write(json.dumps(args) + "\\n")

command = []
index = 0
while index < len(args):
    if args[index] == "--config":
        index += 2
        continue
    command.append(args[index])
    index += 1

if command == ["--version"]:
    print(json.dumps({"name": "geoflow", "version": "0.2.0"}))
    raise SystemExit(0)
if command == ["--help"]:
    print("GEOFlow CLI 0.2.0")
    print("  geoflow catalog")
    print("  geoflow material summary")
    print("  geoflow task list")
    print("  geoflow article list")
    raise SystemExit(0)
if command == ["config", "show"]:
    print(json.dumps({
        "base_url": "https://geoflow.example.test",
        "token_masked": "geo***oken",
        "credential_binding_valid": True,
    }))
    raise SystemExit(0)

status = os.environ.get("GEOFLOW_FAKE_CLI_STATUS", "")
if command == ["catalog"] and status:
    code = int(status)
    messages = {
        401: "Token invalid",
        403: "Forbidden",
        423: "Resource locked",
        429: "Too many requests",
    }
    message = messages[code] + os.environ.get("GEOFLOW_FAKE_CLI_MESSAGE", "")
    print(json.dumps({
        "success": False,
        "status": code,
        "error": {
            "code": "fake_error",
            "message": message,
            "details": {"retry_after": 17, "token": "fake-response-token"},
        },
    }), file=sys.stderr)
    raise SystemExit(1)

print(json.dumps({"success": True, "data": {"command": command}}))
""",
            encoding="utf-8",
        )
        cli.chmod(0o755)
        return workspace, root / "cli.log"

    def test_cli_preflight_dispatches_requested_checks_with_explicit_config(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            workspace, log_path = self.make_cli_workspace(root)
            config_path = root / "profile.json"
            env = os.environ.copy()
            env["GEOFLOW_FAKE_CLI_LOG"] = str(log_path)

            completed = subprocess.run(
                [
                    "bash",
                    str(SCRIPT_DIR / "geoflow_preflight.sh"),
                    str(workspace),
                    str(config_path),
                    "catalog,materials,tasks,articles",
                ],
                text=True,
                capture_output=True,
                env=env,
                check=False,
            )

            self.assertEqual(0, completed.returncode, completed.stderr)
            calls = [json.loads(line) for line in log_path.read_text(encoding="utf-8").splitlines()]
            prefix = ["--config", str(config_path)]
            self.assertEqual([
                prefix + ["--version"],
                prefix + ["--help"],
                prefix + ["config", "show"],
                prefix + ["catalog"],
                prefix + ["material", "summary"],
                prefix + ["task", "list", "--per-page", "1"],
                prefix + ["article", "list", "--per-page", "1"],
            ], calls)

    def test_cli_preflight_explains_401_and_429_without_leaking_secrets(self) -> None:
        for status, expected in (("401", "authentication failed"), ("429", "retry_after")):
            with self.subTest(status=status), tempfile.TemporaryDirectory() as temp_dir:
                root = Path(temp_dir)
                workspace, log_path = self.make_cli_workspace(root)
                env = os.environ.copy()
                env.update({
                    "GEOFLOW_FAKE_CLI_LOG": str(log_path),
                    "GEOFLOW_FAKE_CLI_STATUS": status,
                })

                completed = subprocess.run(
                    ["bash", str(SCRIPT_DIR / "geoflow_preflight.sh"), str(workspace)],
                    text=True,
                    capture_output=True,
                    env=env,
                    check=False,
                )

                combined = completed.stdout + completed.stderr
                self.assertNotEqual(0, completed.returncode)
                self.assertIn(expected, combined)
                self.assertIn("[redacted]", combined)
                self.assertNotIn("fake-response-token", combined)

    def test_cli_preflight_redacts_message_secrets_and_quotes_recovery_hint(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            workspace_marker = root / "injected_workspace"
            config_marker = root / "injected_config"
            workspace, log_path = self.make_cli_workspace(root, "workspace $(touch injected_workspace)")
            config_path = root / "profile;touch injected_config"
            leaf_secret = "fixture-cli-message-secret"
            environment_token = "fixture-cli-environment-token"
            env = os.environ.copy()
            env.update({
                "GEOFLOW_FAKE_CLI_LOG": str(log_path),
                "GEOFLOW_FAKE_CLI_STATUS": "401",
                "GEOFLOW_API_TOKEN": environment_token,
                "GEOFLOW_FAKE_CLI_MESSAGE": (
                    f" token={leaf_secret} password={leaf_secret} secret={leaf_secret} "
                    f"api_key={leaf_secret} Authorization: Bearer {leaf_secret} current={environment_token}"
                ),
            })

            completed = subprocess.run(
                [
                    "bash",
                    str(SCRIPT_DIR / "geoflow_preflight.sh"),
                    str(workspace),
                    str(config_path),
                    "catalog",
                ],
                text=True,
                capture_output=True,
                env=env,
                check=False,
            )

            self.assertNotEqual(0, completed.returncode)
            combined = completed.stdout + completed.stderr
            if leaf_secret in combined or environment_token in combined:
                self.fail("preflight printed a credential embedded in a CLI JSON message")
            hint_line = next(line for line in completed.stderr.splitlines() if "Refresh the saved token with:" in line)
            hint = hint_line.split("with:", 1)[1].strip()
            self.assertNotIn("<", hint)
            self.assertNotIn(">", hint)
            self.assertNotIn("URL", hint)
            self.assertNotIn("USERNAME", hint)
            self.assertNotIn("--base-url", hint)
            self.assertNotIn("--username", hint)
            replay = subprocess.run(
                ["bash", "-c", hint],
                cwd=root,
                text=True,
                capture_output=True,
                env=env,
                check=False,
            )
            self.assertEqual(0, replay.returncode, replay.stderr)
            replay_calls = [json.loads(line) for line in log_path.read_text(encoding="utf-8").splitlines()]
            self.assertEqual(["--config", str(config_path), "login", "--force"], replay_calls[-1])
            self.assertFalse(workspace_marker.exists(), "recovery hint executed workspace metacharacters")
            self.assertFalse(config_marker.exists(), "recovery hint executed config metacharacters")


class StaticPreviewTest(unittest.TestCase):
    def test_bundled_preview_path_exists(self) -> None:
        skill_root = SCRIPT_DIR.parent
        self.assertTrue((skill_root / serve_preview.PREVIEW_RELATIVE_PATH).is_file())

    def test_preview_server_exposes_only_bundled_allowlist(self) -> None:
        for request_path in serve_preview.ALLOWED_PREVIEW_PATHS:
            with self.subTest(request_path=request_path):
                resolved = serve_preview.preview_file_for_request(request_path)
                self.assertIsNotNone(resolved)
                self.assertTrue(resolved.is_file())
                self.assertTrue(resolved.is_relative_to(serve_preview.PREVIEW_ROOT.resolve()))

        for blocked in ("/.env", "/../SKILL.md", "/assets/../index.html", "/", "/unknown.json"):
            with self.subTest(blocked=blocked):
                if blocked == "/":
                    self.assertEqual(
                        serve_preview.PREVIEW_ROOT.resolve() / "index.html",
                        serve_preview.preview_file_for_request(blocked),
                    )
                else:
                    self.assertIsNone(serve_preview.preview_file_for_request(blocked))

    def test_preview_metadata_is_static_and_text_only(self) -> None:
        source = (serve_preview.PREVIEW_ROOT / "assets/app.js").read_text(encoding="utf-8")
        self.assertIn("textContent", source)
        self.assertNotIn("innerHTML", source)
        self.assertNotIn("fetch(", source)
        self.assertNotIn("outputs/", source)


class PackageContractTest(unittest.TestCase):
    @property
    def skill_root(self) -> Path:
        return SCRIPT_DIR.parent

    def test_api_fallback_curl_calls_disable_globbing(self) -> None:
        command_map = (self.skill_root / "references/command-map.md").read_text(encoding="utf-8")
        self.assertEqual(command_map.count("curl --disable"), command_map.count("curl --disable --globoff"))
        preflight = (self.skill_root / "scripts/geoflow_preflight.sh").read_text(encoding="utf-8")
        self.assertIn("curl --disable --globoff --proto", preflight)
        self.assertIn("curl_args=(--disable --globoff --proto", preflight)

    def test_documented_api_fallback_redacts_error_body_before_stderr(self) -> None:
        source = (self.skill_root / "references/command-map.md").read_text(encoding="utf-8")
        login_section = source.split("### API-only fallback", 1)[1].split(
            "Then run the protected login flow:", 1
        )[0]
        transport_script = login_section.split("```bash", 1)[1].split("```", 1)[0]
        fallback_section = source.split("## API v1 Fallback", 1)[1].split("## Admin Web Boundary", 1)[0]
        setup_script = fallback_section.split("```bash", 1)[1].split("```", 1)[0]

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            fake_bin, call_log = PreflightStreamingLimitTest.make_fake_curl(root)
            message_secret = "fixture-fallback-message-secret"
            environment_secret = "fixture-fallback-environment-secret"
            response = json.dumps({
                "success": False,
                "error": {
                    "message": f"token={message_secret} current={environment_secret}",
                    "details": {"password": "fixture-fallback-password"},
                },
            })
            env = os.environ.copy()
            env.update({
                "PATH": f"{fake_bin}:{env['PATH']}",
                "GEOFLOW_BASE_URL": "https://geoflow.example.test",
                "GEOFLOW_API_TOKEN": environment_secret,
                "GEOFLOW_FAKE_CURL_CALL": str(call_log),
                "GEOFLOW_FAKE_HTTP_STATUS": "500",
                "GEOFLOW_FAKE_BODY_TEXT": response,
            })
            script = transport_script + "\n" + setup_script + "\ngeoflow_api_request \\\n+  -H 'Accept: application/json' \\\n+  \"$geoflow_api_base_url/api/v1/catalog\"\n"

            completed = subprocess.run(
                ["/bin/bash", "-c", script],
                text=True,
                capture_output=True,
                env=env,
                check=False,
            )

            combined = completed.stdout + completed.stderr
            self.assertNotEqual(0, completed.returncode)
            self.assertIn("HTTP 500", combined)
            self.assertIn("[redacted]", combined)
            for secret in (message_secret, environment_secret, "fixture-fallback-password"):
                self.assertNotIn(secret, combined)
            curl_args = json.loads(call_log.read_text(encoding="utf-8"))
            self.assertNotIn("--location", curl_args)

    def test_api_only_first_login_keeps_secrets_in_protected_files(self) -> None:
        source = (self.skill_root / "references/command-map.md").read_text(encoding="utf-8")
        section = source.split("## First Login", 1)[1].split("## CLI 0.2.0 Command Reference", 1)[0]
        api_only_section = section.split("### API-only fallback", 1)[1]
        bash_blocks = re.findall(r"```bash\n(.*?)\n```", api_only_section, flags=re.DOTALL)
        self.assertGreaterEqual(len(bash_blocks), 2)
        script = bash_blocks[0] + "\n" + bash_blocks[1]
        self.assertIn("--globoff", script)
        self.assertIn('if "{" in value or "}" in value:', script)
        self.assertNotIn("print(token)", section)
        self.assertNotIn("export GEOFLOW_API_TOKEN", section)
        self.assertIn('"--location"', script)
        self.assertNotIn("curl --disable --globoff --location", section)
        self.assertNotIn("--insecure", section)

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            fake_bin = root / "bin"
            fake_bin.mkdir()
            protected_root = root / "protected"
            protected_root.mkdir()
            ready_path = root / "request-response-removed"
            continue_path = root / "continue"
            request_path = protected_root / "request"
            response_path = protected_root / "response"
            error_path = protected_root / "error"
            header_path = protected_root / "header"

            fake_mktemp = fake_bin / "mktemp"
            fake_mktemp.write_text(
                """#!/usr/bin/env python3
import os
from pathlib import Path

root = Path(os.environ["GEOFLOW_TEST_PROTECTED_ROOT"])
counter = root / "counter"
index = int(counter.read_text(encoding="utf-8")) if counter.exists() else 0
names = ("request", "response", "error", "header")
if index >= len(names):
    raise SystemExit("unexpected mktemp call")
path = root / names[index]
path.touch(mode=0o600, exist_ok=False)
counter.write_text(str(index + 1), encoding="utf-8")
print(path)
""",
                encoding="utf-8",
            )
            fake_mktemp.chmod(0o755)

            fake_rm = fake_bin / "rm"
            fake_rm.write_text(
                """#!/usr/bin/env python3
import os
import subprocess
import sys
import time
from pathlib import Path

targets = [argument for argument in sys.argv[1:] if argument != "-f"]
subprocess.run(["/bin/rm", "-f", *targets], check=True)
root = Path(os.environ["GEOFLOW_TEST_PROTECTED_ROOT"])
request = str(root / "request")
response = str(root / "response")
error = str(root / "error")
header = str(root / "header")
if request in targets and response in targets and error in targets and header not in targets:
    Path(os.environ["GEOFLOW_TEST_READY"]).touch()
    continue_path = Path(os.environ["GEOFLOW_TEST_CONTINUE"])
    deadline = time.monotonic() + 10
    while not continue_path.exists():
        if time.monotonic() >= deadline:
            raise SystemExit("test continuation timed out")
        time.sleep(0.01)
""",
                encoding="utf-8",
            )
            fake_rm.chmod(0o755)

            fixture_password = "fixture-password-value"
            fixture_token = "fixture-token-value"
            observed: dict[str, bool] = {}

            class LoginHandler(BaseHTTPRequestHandler):
                def do_POST(self) -> None:
                    length = int(self.headers.get("Content-Length", "0"))
                    payload = json.loads(self.rfile.read(length))
                    observed["request_ok"] = payload == {
                        "username": "admin",
                        "password": fixture_password,
                    }
                    observed["modes_ok"] = all(
                        path.exists() and stat.S_IMODE(path.stat().st_mode) == 0o600
                        for path in (request_path, response_path, error_path, header_path)
                    )
                    response = json.dumps({"success": True, "data": {"token": fixture_token}}).encode()
                    self.send_response(200)
                    self.send_header("Content-Type", "application/json")
                    self.send_header("Content-Length", str(len(response)))
                    self.end_headers()
                    self.wfile.write(response)

                def log_message(self, *_args: object) -> None:
                    return

            server = HTTPServer(("127.0.0.1", 0), LoginHandler)
            server_thread = threading.Thread(target=server.serve_forever, daemon=True)
            env = os.environ.copy()
            env.update({
                "PATH": f"{fake_bin}:{env['PATH']}",
                "GEOFLOW_BASE_URL": f"http://127.0.0.1:{server.server_port}",
                "GEOFLOW_TEST_PROTECTED_ROOT": str(protected_root),
                "GEOFLOW_TEST_READY": str(ready_path),
                "GEOFLOW_TEST_CONTINUE": str(continue_path),
            })

            pid = -1
            file_descriptor = -1
            child_status = None
            output = bytearray()
            sent_username = False
            sent_password = False
            inspected_intermediate_state = False
            try:
                pid, file_descriptor = pty.fork()
                if pid == 0:
                    os.execve("/bin/bash", ["bash", "-c", script], env)
                server_thread.start()

                deadline = time.monotonic() + 15
                while time.monotonic() < deadline:
                    if ready_path.exists() and not inspected_intermediate_state:
                        self.assertFalse(request_path.exists())
                        self.assertFalse(response_path.exists())
                        self.assertFalse(error_path.exists())
                        self.assertTrue(header_path.is_file())
                        self.assertEqual(0o600, stat.S_IMODE(header_path.stat().st_mode))
                        header_is_valid = header_path.read_text(encoding="utf-8").startswith("Authorization: Bearer ")
                        self.assertTrue(header_is_valid, "protected authorization header was not generated")
                        inspected_intermediate_state = True
                        continue_path.touch()

                    ready, _, _ = select.select([file_descriptor], [], [], 0.1)
                    if ready:
                        try:
                            chunk = os.read(file_descriptor, 4096)
                        except OSError as exc:
                            if exc.errno == errno.EIO:
                                break
                            raise
                        if not chunk:
                            break
                        output.extend(chunk)
                        if b"Admin username:" in output and not sent_username:
                            os.write(file_descriptor, b"admin\n")
                            sent_username = True
                        if b"Admin password:" in output and not sent_password:
                            os.write(file_descriptor, fixture_password.encode() + b"\n")
                            sent_password = True

                    waited_pid, status_value = os.waitpid(pid, os.WNOHANG)
                    if waited_pid:
                        child_status = status_value
                        break
                if child_status is None:
                    waited_pid, status_value = os.waitpid(pid, os.WNOHANG)
                    if waited_pid:
                        child_status = status_value
                if child_status is None:
                    raise AssertionError("API-only login flow timed out")
            finally:
                continue_path.touch(exist_ok=True)
                if pid > 0 and child_status is None:
                    os.kill(pid, 9)
                    os.waitpid(pid, 0)
                if file_descriptor >= 0:
                    os.close(file_descriptor)
                server.shutdown()
                server.server_close()
                if server_thread.ident is not None:
                    server_thread.join(timeout=5)

            rendered_output = output.decode("utf-8", errors="replace")
            safe_output = rendered_output.replace(fixture_password, "[redacted]").replace(fixture_token, "[redacted]")
            self.assertEqual(0, os.waitstatus_to_exitcode(child_status), safe_output)
            self.assertTrue(inspected_intermediate_state)
            self.assertTrue(observed.get("request_ok", False))
            self.assertTrue(observed.get("modes_ok", False))
            if fixture_password in rendered_output or fixture_token in rendered_output:
                self.fail("API-only login flow printed a credential")
            self.assertFalse(request_path.exists())
            self.assertFalse(response_path.exists())
            self.assertFalse(error_path.exists())
            self.assertFalse(header_path.exists())

    def test_lead_form_contract_stays_synchronized(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            workspace = Path(temp_dir)
            controller = workspace / "app/Http/Controllers/Site/HomeController.php"
            controller.parent.mkdir(parents=True)
            controller.write_text(
                "<?php $homepageModules = []; $homepageStyle = []; $showHomepageModules = true;",
                encoding="utf-8",
            )
            partial = workspace / "resources/views/site/partials/homepage-modules.blade.php"
            partial.parent.mkdir(parents=True)
            partial.write_text("lead_form", encoding="utf-8")
            contract = discover_themes.detect_homepage_contract(workspace)

        self.assertIn("home.builder.lead_form", contract["safe_homepage_modules"])
        required_mentions = {
            "references/homepage-composition-guide.md": ("`lead_form`", "`lead_form_slug`"),
            "references/laravel-theme-contract.md": ("lead_form", "lead_form_slug"),
            "references/template-boundary.md": ("`lead_form`", "`lead_form_slug`"),
            "references/theme-package-contract.md": ("home.builder.lead_form", "lead_form_slug"),
        }
        for relative_path, needles in required_mentions.items():
            content = (self.skill_root / relative_path).read_text(encoding="utf-8")
            for needle in needles:
                self.assertIn(needle, content, f"{needle} missing from {relative_path}")

    def test_skill_ir_matches_trigger_and_target_contracts(self) -> None:
        trigger_cases = json.loads((self.skill_root / "evals/trigger_cases.json").read_text(encoding="utf-8"))
        skill_ir = json.loads((self.skill_root / "reports/skill-ir.json").read_text(encoding="utf-8"))
        manifest = json.loads((self.skill_root / "manifest.json").read_text(encoding="utf-8"))

        self.assertEqual(
            [case["text"] for case in trigger_cases["should_trigger"]],
            skill_ir["trigger_surface"]["should_trigger"],
        )
        self.assertEqual(
            [case["text"] for case in trigger_cases["should_not_trigger"]],
            skill_ir["trigger_surface"]["should_not_trigger"],
        )
        self.assertEqual(
            [case["text"] for case in trigger_cases["near_neighbor"]],
            skill_ir["trigger_surface"]["edge_cases"],
        )
        self.assertEqual(manifest["target_platforms"], skill_ir["targets"])
        self.assertTrue(skill_ir["workflow"]["decision_points"])
        self.assertTrue(skill_ir["workflow"]["failure_modes"])
        self.assertTrue(skill_ir["eval_plan"]["output"])
        self.assertTrue(skill_ir["eval_plan"]["adversarial"])

    def test_reviewed_network_inventory_covers_all_live_entrypoints(self) -> None:
        policy = json.loads((self.skill_root / "security/network_policy.json").read_text(encoding="utf-8"))
        expected = {
            "scripts/geoflow_preflight.sh",
            "scripts/build_sync_preview_report.py",
            "scripts/compare_default_vs_channel_frontend.py",
        }
        self.assertEqual(expected, set(policy["scripts"]))

        report = (self.skill_root / "reports/security_trust_report.md").read_text(encoding="utf-8")
        self.assertIn("Outbound or delegated-live network entrypoints: `3`", report)
        self.assertIn("Network policy covered scripts: `3`", report)
        for relative_path in expected:
            self.assertIn(relative_path, report)
        self.assertNotIn("Network-capable scripts: `0`", report)

    def test_expected_artifact_contract_matches_public_package(self) -> None:
        contract = json.loads((self.skill_root / "evals/expected_artifacts.json").read_text(encoding="utf-8"))
        expected = sorted(contract["required_package_files"])
        ignored_generated = {
            "reports/conformance_matrix.json",
            "reports/security_trust_report.json",
        }
        actual = sorted(
            path.relative_to(self.skill_root).as_posix()
            for path in self.skill_root.rglob("*")
            if path.is_file()
            and "__pycache__" not in path.parts
            and path.suffix != ".pyc"
            and path.relative_to(self.skill_root).as_posix() not in ignored_generated
        )
        self.assertEqual(expected, actual)

    def test_installer_replaces_cleanly_and_preserves_backups(self) -> None:
        contract = json.loads((self.skill_root / "evals/expected_artifacts.json").read_text(encoding="utf-8"))
        expected = sorted(contract["required_package_files"])
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            skills_root = root / "skills"
            backup_root = root / "backups"
            for skill_name in ("geoflow", "yao-geoflow-cli", "yao-geoflow-design", "yao-geoflow-template"):
                installed = skills_root / skill_name
                installed.mkdir(parents=True)
                (installed / "stale.txt").write_text(skill_name, encoding="utf-8")

            env = os.environ.copy()
            env.update({
                "GEOFLOW_CODEX_SKILLS_ROOT": str(skills_root),
                "GEOFLOW_SKILL_BACKUP_ROOT": str(backup_root),
                "PYTHONDONTWRITEBYTECODE": "1",
            })
            completed = subprocess.run(
                ["bash", str(SCRIPT_DIR / "install_codex_skill.sh")],
                text=True,
                capture_output=True,
                env=env,
                check=False,
            )

            self.assertEqual(0, completed.returncode, completed.stderr)
            installed_root = skills_root / "geoflow"
            actual = sorted(
                path.relative_to(installed_root).as_posix()
                for path in installed_root.rglob("*")
                if path.is_file()
            )
            self.assertEqual(expected, actual)
            self.assertFalse((installed_root / "stale.txt").exists())
            backups = list(backup_root.glob("geoflow-*"))
            self.assertEqual(1, len(backups))
            for skill_name in ("geoflow", "yao-geoflow-cli", "yao-geoflow-design", "yao-geoflow-template"):
                self.assertTrue((backups[0] / skill_name / "stale.txt").is_file())


if __name__ == "__main__":
    unittest.main()

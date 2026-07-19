#!/usr/bin/env python3

import json
import os
import subprocess
import sys
import tempfile
import unittest
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
import sys
from pathlib import Path

args = sys.argv[1:]
output_flag = "--output" if "--output" in args else "-o"
output = Path(args[args.index(output_flag) + 1])
output.write_text(
    '{"message":"server error","token":"response-token","nested":{"password":"response-password"}}',
    encoding="utf-8",
)
if "--write-out" in args:
    print("500", end="")
""",
                encoding="utf-8",
            )
            fake_curl.chmod(0o755)

            env = os.environ.copy()
            env.update({
                "PATH": f"{fake_bin}:{env['PATH']}",
                "GEOFLOW_BASE_URL": "https://geoflow.example.test",
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
            self.assertIn("HTTP 500", completed.stderr)
            self.assertIn("[redacted]", completed.stderr)
            self.assertNotIn("response-token", completed.stdout + completed.stderr)
            self.assertNotIn("response-password", completed.stdout + completed.stderr)

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

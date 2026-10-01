"""Topic discovery and preview-fork regressions for the GEOFlow skill."""

import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

SCRIPTS = Path(__file__).resolve().parents[1] / "scripts"
sys.path.insert(0, str(SCRIPTS))
import discover_themes
import discover_geoflow_workspace
import discover_frontend_surfaces


class TopicSupportTest(unittest.TestCase):
    def test_topic_additions_preserve_packaged_updater_contract(self):
        root = SCRIPTS.parent
        contract = json.loads((root / "evals/expected_artifacts.json").read_text())
        required = set(contract["required_package_files"])
        for relative in ("references/topic-workflow.md", "evals/test_topic_support.py", "references/remote-cli-workflow.md", "references/remote-updater-workflow.md", "reports/coordinated-updater-review.md"):
            self.assertIn(relative, required)
            self.assertTrue((root / relative).is_file())
        for boundary in ("updater-uncertain-replay", "updater-v1-write-fallback", "held-background-reported-as-success"):
            self.assertIn(boundary, contract["forbidden_behaviors"])
        triggers = json.loads((root / "evals/trigger_cases.json").read_text())
        families = {case["family"] for case in triggers["should_trigger"]}
        self.assertTrue({"remote_updater_plan", "remote_updater_recovery", "operations_topics", "public_frontend_topics", "theme_replication_topics", "theme_library_topics"}.issubset(families))
        negative_families = {case["family"] for case in triggers["adversarial"]}
        self.assertTrue({"updater_uncertain_replay", "updater_unsafe_fallback"}.issubset(negative_families))

    def test_topic_skill_description_and_ir_share_one_trigger_contract(self):
        root = SCRIPTS.parent
        description = (root / "SKILL.md").read_text().split("description: ", 1)[1].split("\n", 1)[0]
        ir = json.loads((root / "reports/skill-ir.json").read_text())
        self.assertEqual(description, ir["job_to_be_done"])
        self.assertEqual(description, ir["trigger_surface"]["description"])
        self.assertIn("topics/专题", description)
        self.assertIn("planned Updater", description)
        self.assertIn("references/topic-workflow.md", ir["resources"]["references"])
        self.assertIn("references/remote-updater-workflow.md", ir["resources"]["references"])
        self.assertIn("evals/test_topic_support.py", ir["eval_plan"]["output"])

    def fixture(self, root):
        files = {
            "artisan": "<?php",
            "routes/web.php": "Route::get('/topics', ...)->name('site.topics.index');\nRoute::get('/topics/{slug}', ...)->name('site.topics.show');\nrequire __DIR__.'/admin-topics.php';",
            "routes/admin-topics.php": "Route::prefix('topics');",
            "app/Models/Topic.php": "<?php class Topic {}",
            "app/Http/Controllers/Site/TopicController.php": "<?php",
            "app/Http/Controllers/Admin/TopicController.php": "<?php",
            "app/Services/Topics/TopicTemplateCatalog.php": "<?php",
            "app/Support/Site/HomepageModuleBuilder.php": "<?php public const TYPES = ['article_collection', 'topic_collection'];",
            "app/Services/Api/ThemeWorkspacePreview.php": "<?php 'topics-index' => 'topics', 'topics-show' => null, 'topics-empty' => 'topics'",
            "app/Http/Controllers/Site/HomeController.php": "<?php ['homeTopics' => $topics]",
            "resources/views/site/topics/index.blade.php": "Index",
            "resources/views/site/topics/show.blade.php": "Show",
            "resources/views/site/topics/templates/default.blade.php": "Default",
            "resources/views/site/topics/templates/guide.blade.php": "Guide",
            "resources/views/site/topics/templates/roundup.blade.php": "Roundup",
            "resources/views/site/partials/topic-home.blade.php": "Home",
            "resources/views/theme/base/manifest.json": json.dumps({"id": "base", "topic": {"contract": 1, "layouts": [{"id": "default", "name": "Default", "view": "site.topics.templates.default"}, {"id": "custom", "name": "Custom", "view": "topics/templates/custom.blade.php"}]}}),
            "resources/views/theme/base/topics/templates/custom.blade.php": "{{ $topic['title'] }}",
            "public/themes/base/topics.css": "/* topic css */",
            "public/themes/base/topics.js": "/* topic js */",
        }
        for relative, content in files.items():
            path = root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content, encoding="utf-8")

    def test_workspace_discovery_identifies_topics_from_current_source(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            self.fixture(root)
            topics = discover_geoflow_workspace.build_snapshot(root)["capabilities"]["topics"]
            self.assertTrue(topics["available"])
            self.assertIn("routes/admin-topics.php", topics["evidence"])

    def test_topic_contract_reports_pages_builder_and_signed_preview(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            self.fixture(root)
            contract = discover_themes.detect_workspace(root)["topic_contract"]
            self.assertTrue(contract["available"])
            self.assertEqual(["/topics", "/topics/{slug}"], contract["public_route_samples"])
            self.assertTrue(contract["homepage_collection"])
            self.assertTrue(contract["signed_workspace_preview"])
            self.assertIn("home.topic_collection", discover_themes.detect_homepage_contract(root)["safe_homepage_modules"])
            self.assertEqual(contract, discover_frontend_surfaces.default_site_surface(root)["topics"])
            (root / "app/Services/Topics/TopicTemplateCatalog.php").unlink()
            self.assertFalse(discover_themes.detect_topic_contract(root)["available"])

    def test_theme_discovery_keeps_nested_topic_views_assets_and_fallback(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            self.fixture(root)
            record = discover_themes.theme_record(root, root / "resources/views/theme/base", "laravel")
            self.assertIn("topics/templates/custom.blade.php", record["editable_files"])
            self.assertTrue(record["public_assets"]["topics_css"])
            self.assertTrue(record["public_assets"]["topics_js"])
            self.assertEqual("theme_manifest", record["topic"]["source"])
            self.assertEqual("custom", record["topic"]["declaration"]["layouts"][1]["id"])
            (root / "resources/views/theme/base/manifest.json").write_text("{}")
            record = discover_themes.theme_record(root, root / "resources/views/theme/base", "laravel")
            self.assertEqual("core_fallback", record["topic"]["source"])
            self.assertEqual(["/topics", "/topics/{slug}"], record["preview_routes"][-2:])

    def test_preview_fork_lists_topic_assets_and_routes_without_changing_base(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            self.fixture(root)
            before = (root / "public/themes/base/topics.css").read_bytes()
            result = subprocess.run([sys.executable, str(SCRIPTS / "prepare_theme_edit_session.py"), str(root), "--base-theme", "base", "--new-theme-id", "base-edit"], text=True, capture_output=True)
            self.assertEqual(0, result.returncode, result.stderr)
            session = json.loads((root / "resources/views/theme/base-edit/edit-session.json").read_text())
            self.assertIn("/topics/{slug}", session["preview_routes"])
            self.assertIn("public/themes/base-edit/topics.css", session["editable_files"])
            self.assertIn("public/themes/base-edit/topics.js", session["editable_files"])
            self.assertIn("topics/templates/custom.blade.php", session["editable_files"])
            self.assertEqual(before, (root / "public/themes/base-edit/topics.css").read_bytes())
            self.assertEqual(before, (root / "public/themes/base/topics.css").read_bytes())

    def test_legacy_workspace_does_not_claim_topic_support(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "themes/base").mkdir(parents=True)
            record = discover_themes.theme_record(root, root / "themes/base", "legacy_php")
            self.assertEqual({}, record["topic"])
            self.assertEqual({}, discover_themes.detect_workspace(root)["topic_contract"])

    def test_incomplete_core_layouts_cannot_be_advertised_as_fallback(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            self.fixture(root)
            (root / "resources/views/theme/base/manifest.json").write_text("{}")
            (root / "resources/views/site/topics/templates/default.blade.php").unlink()
            self.assertFalse(discover_themes.detect_topic_contract(root)["available"])
            record = discover_themes.theme_record(root, root / "resources/views/theme/base", "laravel")
            self.assertEqual("unavailable", record["topic"]["source"])

    def test_discovered_homepage_topic_module_passes_payload_validation(self):
        result = subprocess.run([sys.executable, str(SCRIPTS / "validate_homepage_design_payload.py"), "-"], input=json.dumps({"modules": [{"type": "topic_collection", "title": "专题", "limit": 6}]}), text=True, capture_output=True)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertTrue(json.loads(result.stdout)["ok"])


if __name__ == "__main__":
    unittest.main()

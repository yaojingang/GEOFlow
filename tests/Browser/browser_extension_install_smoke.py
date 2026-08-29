import os
import tempfile
from pathlib import Path

from playwright.sync_api import sync_playwright


extension_path = Path(os.environ.get("GEOFLOW_EXTENSION_PATH", "browser-extension")).resolve()
assert (extension_path / "manifest.json").is_file(), extension_path

with tempfile.TemporaryDirectory(prefix="geoflow-extension-profile-") as profile_dir:
    with sync_playwright() as playwright:
        context = playwright.chromium.launch_persistent_context(
            profile_dir,
            channel="chromium",
            headless=True,
            args=[
                f"--disable-extensions-except={extension_path}",
                f"--load-extension={extension_path}",
            ],
        )
        service_worker = context.service_workers[0] if context.service_workers else context.wait_for_event("serviceworker")
        extension_id = service_worker.url.split("/")[2]
        page = context.new_page()
        page.goto(f"chrome-extension://{extension_id}/src/sidepanel/sidepanel.html")
        page.locator("#connect-form").wait_for(state="visible")
        manifest = page.evaluate("chrome.runtime.getManifest()")

        assert manifest["manifest_version"] == 3
        assert manifest["version"] == "0.1.0"
        assert set(manifest["permissions"]) == {"activeTab", "scripting", "sidePanel", "storage"}
        assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth")
        context.close()

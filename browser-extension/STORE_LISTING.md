# Chrome Web Store listing draft

## Name

GEOFlow Chrome Operator

## Short description

Claim GEOFlow publishing work and safely fill drafts in websites where you are already signed in.

## Detailed description

GEOFlow Chrome Operator gives content operations teams a controlled bridge between self-hosted GEOFlow work orders and a user's existing Chrome session.

Operators can review assigned work, claim one work order, open its target page, copy content, and return a completion receipt. The included Zhihu answer adapter verifies the expected profile before filling plain text into an empty answer editor. The operator remains responsible for reviewing the draft and clicking the platform's final Publish button.

The extension requests website access only when the user connects a GEOFlow instance or opens a target platform. It does not collect platform credentials, cookies, page bodies, or browsing history.

## Category

Productivity

## Language support

- Chinese, Simplified
- English
- Spanish
- Japanese
- Portuguese, Brazil
- Russian

## Permission justification

- `sidePanel`: provides a persistent work queue next to the target page.
- `storage`: stores the scoped GEOFlow connection and current task state.
- `scripting`: runs a packaged adapter after the operator requests draft filling.
- `activeTab`: limits page interaction to the operator's active workflow.
- Optional host access: requested for the configured GEOFlow instance and each target platform origin.

## Support

<https://github.com/yaojingang/GEOFlow/issues>

## Pre-submission checklist

- Package contains no remote executable code.
- Privacy notice is published at a stable public URL.
- Store screenshots show connection, work queue, and filled-draft confirmation.
- Dedicated test accounts are used for real-platform acceptance.
- Version, release notes, and minimum Chrome version match `manifest.json`.
- Web Store upload is performed only after explicit release approval.

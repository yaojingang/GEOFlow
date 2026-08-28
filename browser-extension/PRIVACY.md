# GEOFlow Chrome Operator Privacy Notice

Last updated: 2026-08-24

GEOFlow Chrome Operator connects only to the GEOFlow instance and publishing websites that the user explicitly authorizes.

## Data handled

- GEOFlow connection URL and a scoped browser access token.
- Assigned work-order content, target URL, account profile URL, and execution status.
- The profile URL observed on a supported publishing page, used locally to prevent operation under the wrong account.
- A completion receipt containing status, result URL, client versions, timestamps, target origin, and, when verified by a packaged adapter, a hash of the profile URL observed on the page.

## Storage and transmission

- The scoped GEOFlow token is stored in Chrome local extension storage restricted to trusted extension contexts.
- Active work-order content is stored in Chrome session storage and is cleared when the browser session ends or the task is resolved.
- Work-order data and completion receipts are exchanged directly with the user-configured GEOFlow instance.
- Platform cookies, passwords, request headers, page bodies, and DOM snapshots are not collected or transmitted.

## Use and sharing

Data is used only to display assigned work, fill an operator-approved draft, prevent wrong-account actions, and record the operator's result. The extension does not sell data, use it for advertising, or transmit it to the GEOFlow project maintainers.

Self-hosted GEOFlow administrators control their server-side retention and access policies.

## Control and deletion

Users can disconnect the extension from the side panel or revoke a browser connection from GEOFlow account settings. Disconnecting deletes the local token and active task state. Server-side work-order and audit records remain subject to the configured GEOFlow retention policy.

Support and privacy questions: <https://github.com/yaojingang/GEOFlow/issues>

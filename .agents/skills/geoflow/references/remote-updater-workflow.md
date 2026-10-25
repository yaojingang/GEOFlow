# Remote Updater workflow — coordinated preview

## Discover and authorize the target

Use the selected profile and installed `geoflow`. Read `--help`, `whoami`, `capabilities`, `doctor`, then `updater status`. Core must report the real host v2 protocol, supported actions, plans, request lookup, recovery epoch and maintenance requirements. A missing endpoint, mismatched recovery mount or unavailable socket prevents new remote actions. Keep diagnostics read-only. Never retry through v1 or invent a host command.

The current active super administrator needs explicit scopes. Request `updater:read,updater:plan` plus the action's scope at login: `updater:update` for update and switch-back; `updater:backup` for backup; `updater:restore` for full restore. Old `*` tokens do not acquire these scopes. Keep read scope for receipt recovery. Current authorization controls reads even when the historical actor was a different account incarnation.

Check existing user authorization for the exact instance, target and effect. The source implementation alone does not authorize a production operation. Reuse authorization already granted for the same action; ask only for missing scope or decisions after the plan is concrete.

## Save and inspect a plan

These examples use a placeholder profile; substitute the verified profile. Status and receipt polling never generate plans.

```sh
geoflow updater status --profile staging
geoflow updater plan --profile staging --action backup > backup-plan.json
geoflow updater recovery-points --profile staging
```

For `update` or `switch-back`, change `--action`. For `restore`, use `--action restore --recovery-point ID` with a point returned by discovery. Remote restore permits the latest upgrade checkpoint. Historical restore requires the supported host workflow and explicit administrator review.

Plans expire after ten minutes and bind the source, target, deployment baseline, actor and recovery epoch. Review the actual plan before submitting. A new operation requires a current plan. An already accepted request retains its original plan and epoch for lookup.

Check `maintenance_required`, target/version identities and `continuation`. Backups require a maintenance window. `host_only` means the target cannot support subsequent remote receipt lookup; verify authorized host access before submitting. Retain the request ID outside Core's database.

## Submit once and recover by reading

Choose one unique request ID and retain the original plan. For an authorized backup requiring maintenance:

```sh
geoflow updater backup --profile staging --plan backup-plan.json --client-request-id REQUEST_ID --allow-maintenance
```

The interactive command requests hidden input. Automated runs use `--credentials-file FILE`: a current-user-owned regular file with permission `0600`, containing only `password` and `authorization_code`. The host code is six digits. Supply credentials through a protected local mechanism; keep values out of chat, command arguments, ordinary payload archives, output and logs. Existing Core password configuration remains in force; the host action code remains required. Never obtain or reset an administrator's credentials to bypass authorization.

Use the analogous action command for a reviewed update, switch-back or restore plan. Include `--confirm-host-access` only after verifying the plan's host continuation requirement. The CLI validates local input, durably records the request, then sends once. An accepted receipt identifies the host operation; it does not establish completion.

```sh
geoflow updater operation lookup REQUEST_ID --profile staging
geoflow updater operation wait REQUEST_ID --profile staging --wait-seconds 600
geoflow updater operation get OPERATION_ID --profile staging
```

Waiting uses reads, starts at two seconds, backs off to thirty seconds and honors server delay instructions. It defaults to ten minutes. Authentication or permission rejection ends the wait. Exit `0` means full success; `1` means failed or rolled back; `2` means timeout or recovery attention. Retain the actual receipt and state.

After disconnect, restart or re-login to the same instance/account, query the original request. A prepared local journal permits reads only, including after 404. Neither a missing receipt nor a restored database proves that no side effect happened. Preserve the journal and original ID. New execution requires business reconciliation and explicit authorization for a new request. Plan expiry or a new epoch must not cause automatic resubmission.

If the restored Core lacks new routes, use the approved host receipt lookup. Stop at that boundary if host access is unavailable. Do not fall back to browser uploads, direct database editing, arbitrary shell execution or v1 writes.

## Interpret recovery correctly

- `rolled_back` means the requested target upgrade did not succeed.
- `recovery_required` or `background_status: held` means HTTP may be available while background work still needs reconciliation. Report outstanding work and the receipt.
- Full restore replaces application data and files, invalidates restored credentials and quarantines prior queued work. Unknown intents stay held. Retain their evidence.
- Code switch-back preserves current data and requires compatibility. Theme field rollback has its own contract. Never substitute full restore for undoing a theme change.
- Whole-host rollback or cloning can roll back the host ledger itself. Those workflows require identity re-registration and independent reconciliation.

New Core source includes a host-only `geoflow:recovery-reconcile` command for inspection, append-only decisions and empty-set proofs. These are not remote `geoflow updater` actions. An authorized fixed host executor must check the installed command and contract before use. Every decision retains the original work with `execution_created=false`; even `verified_no_replay` and `reexecute_requested` remain held. A Core empty-set proof covers only the database and does not release background work. The current Updater does not provide the host release-to-ready flow. Preserve the original transaction and recovery point; never edit the host phase or remove evidence to bypass the gate. Nonempty restored work still requires domain-specific replay fences and separate new executions.

## Evidence and limits

Report the redacted profile/instance, actual discovered protocol/actions, plan and request IDs, receipt state, source/target identities, maintenance effects, background status and verified layers. Keep source implementation, local fixtures, installed instance, native architecture rehearsal and official release evidence separate.

This package remains a preview. Verify capabilities on every target. Theme publication remains disabled until both theme and coordinated-upgrade gates pass. Signed public distribution, model-based Skill evaluation and native dual-architecture rehearsals require separate evidence.

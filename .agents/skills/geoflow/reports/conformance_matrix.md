# Runtime Conformance Matrix

- Skill: `geoflow`
- Targets: `5`
- Passed: `5`
- Failed: `0`

| Target | Status | Failures | Warnings |
| --- | --- | --- | --- |
| openai | pass | None | None |
| claude | pass | None | None |
| agent-skills | pass | None | agent-skills uses canonical Agent Skills metadata; provider-native execution transforms are not implemented in v0. |
| vscode | pass | None | vscode uses canonical Agent Skills metadata; provider-native execution transforms are not implemented in v0. |
| generic | pass | None | None |

## Reviewer Notes

- Failed targets block release for that target.
- Warnings identify lossy or not-yet-compiled behavior that must remain visible.

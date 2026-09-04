# GEOFlow v3.0.0 正式发布手册

本手册用于把通过评审的 GEOFlow 3.0 源码、独立更新工具、容器镜像和 GitHub Release 固定为同一组可核验构件。公开 Release 前，所有门禁均需完成；任一项目缺少证据时保留 Draft Release，并停止推进稳定通道。

## 发布契约

| 项目 | 固定值或证据 |
|---|---|
| Core 版本 | `3.0.0` |
| Core 标签 | `v3.0.0` |
| 目标发布日期 | `2026-09-05`；延期时先通过 PR 同步修改 `version.json` 与中英文更新日志 |
| GEOFlow Updater | `v0.3.0` |
| 更新序列 | `2`，严格大于当前公开序列 `1` |
| 内置 CLI | `0.2.0` |
| Chrome 运营助手 | `0.1.0` |
| Core 最终提交 | 发布 PR 合并后记录的 40 位提交 SHA |
| 稳定元数据 | GitHub Release 资产 `version.json` |
| Core 资产 | `GEOFlow-v3.0.0.zip`、`GEOFlow-v3.0.0.zip.sha256`、`version.json` |
| 发布完整性 | GitHub Immutable Releases、`v*` 标签规则、签名标签和 Release attestation |

`main/version.json` 描述当前源码。已安装版本的稳定更新检查读取 `https://github.com/yaojingang/GEOFlow/releases/latest/download/version.json`，因此每个正式 Release 都必须上传独立的 `version.json` 资产，并将正式版本标记为 Latest。

## 零、启用发布完整性保护

以下保护必须在创建 `v3.0.0` 标签前生效：

1. 在仓库 Settings 的 Releases 匹配项中启用 Immutable Releases。该能力会在 Release 公开时锁定标签与资产，并为 Release 生成可验证的 attestation。
2. 创建 Active 状态的 tag ruleset，目标包含 `refs/tags/v*`，启用 Restrict creations、Restrict updates 与 Restrict deletions。bypass actor 只保留受控发布身份，日常写权限不能创建或抢占版本标签。
3. 指定 `yaojingang` 为本次受控发布身份。从创建 Draft Release 到公开后 attestation 验证完成期间，其他协作者、GitHub Apps、Deploy Keys、PAT 和自动化凭据均不得拥有该仓库的 `Contents:write`。将私密权限清点与独立复核证据的 SHA-256 写入 `GEOFLOW_RELEASE_ACCESS_EVIDENCE_SHA256`。
4. 通过 API 回读实际状态；将标签规则集 ID 写入 `GEOFLOW_TAG_RULESET_ID` 后执行：

```bash
set -euo pipefail
: "${GEOFLOW_TAG_RULESET_ID:?set the protected release tag ruleset ID}"
: "${GEOFLOW_RELEASE_ACCESS_EVIDENCE_SHA256:?set the approved release access evidence SHA-256}"
[[ "$GEOFLOW_RELEASE_ACCESS_EVIDENCE_SHA256" =~ ^[a-f0-9]{64}$ ]]

gh api repos/yaojingang/GEOFlow/immutable-releases | jq -e '.enabled == true'
gh api "repos/yaojingang/GEOFlow/rulesets/$GEOFLOW_TAG_RULESET_ID" | jq -e '
  .target == "tag"
  and .enforcement == "active"
  and ((.conditions.ref_name.include | index("refs/tags/v*")) != null)
  and (([.rules[].type] | index("creation")) != null)
  and (([.rules[].type] | index("update")) != null)
  and (([.rules[].type] | index("deletion")) != null)
'
gh api 'repos/yaojingang/GEOFlow/collaborators?affiliation=direct&per_page=100' --paginate | jq -se '
  all(.[][]; .login == "yaojingang" or (((.permissions.admin // false) == false) and ((.permissions.push // false) == false)))
'
gh api repos/yaojingang/GEOFlow/keys --paginate | jq -se 'all(.[][]; .read_only == true)'
gh api repos/yaojingang/GEOFlow/actions/permissions/workflow | jq -e '.default_workflow_permissions == "read"'
```

权限证据必须覆盖 API 无法完整枚举的 GitHub App 安装授权、OAuth/PAT 所有者、仓库与环境密钥，以及计划发布窗口内的其他自动化。任一检查失败时停止发布。Immutable Releases 只保护启用后公开的 Release，现有历史 Release 不会自动获得该保护。

## 一、固定 Core 候选提交

1. 通过 Pull Request 合并本次发布准备变更，确认 CI 的 application 与 PostgreSQL 两个任务通过。
2. 确认 `origin/main` 没有发布范围外的新提交，记录最终 SHA：

```bash
set -euo pipefail

git fetch origin --prune --tags
export GEOFLOW_RELEASE_SHA="$(git rev-parse origin/main)"
git show --no-patch --format=fuller "$GEOFLOW_RELEASE_SHA"
git show "$GEOFLOW_RELEASE_SHA:version.json" | jq -e '.version == "3.0.0" and .tag == "v3.0.0" and .release_date == "2026-09-05"'
```

3. 在该 SHA 上完成 `composer validate --strict`、Pint、生产构建、PHP 与 JavaScript 全量测试、Composer 与 npm 安全审计，以及 `sh bin/git/check-open-source-release.sh`。
4. 检查 `v2.3.0..$GEOFLOW_RELEASE_SHA` 的全部提交、迁移、配置和用户可见变化，确认中英文更新日志及正式发布说明覆盖相同构件边界。

## 二、生成并演练 Updater v0.3.0 候选

从 `yaojingang/geoflow-updater` 的 `main` 分支触发候选工作流，输入 Core 最终 SHA：

```bash
set -euo pipefail

gh workflow run release-candidate.yml --repo yaojingang/geoflow-updater --ref main -f updater_version=0.3.0 -f geoflow_ref="$GEOFLOW_RELEASE_SHA" -f geoflow_version=3.0.0 -f release_sequence=2
```

候选工作流必须生成同一组 linux/amd64 与 linux/arm64 Updater 包、Core app/web 多架构镜像、签名 TUF 元数据和 `candidate.json`。随后严格按照 Updater 仓库的 `docs/phase-c-staging-rehearsal.md`，在两种架构的真实宿主机上演练安装、更新、完整备份、环境验收和恢复点回滚。

两种架构均通过后，把完整证据 JSON 写入受保护环境，记录候选工作流 run ID 与证据 SHA-256。此时保留候选状态，不触发 `release.yml`，避免在 Core Release 完成公开资产回读前提升 3.0 稳定 TUF 目标。常规发布路径使用 `superadmin_risk_waiver=false`；风险豁免不属于本次 3.0 正式发布路径。

## 三、生成并验证 Core 资产

从 Core 最终 SHA 生成临时发布目录和三个固定资产：

```bash
set -euo pipefail

export GEOFLOW_RELEASE_DIR="$(mktemp -d /tmp/geoflow-v3-release.XXXXXX)"
git archive --format=zip --prefix='GEOFlow-3.0.0/' --output="$GEOFLOW_RELEASE_DIR/GEOFlow-v3.0.0.zip" "$GEOFLOW_RELEASE_SHA"
git show "$GEOFLOW_RELEASE_SHA:version.json" > "$GEOFLOW_RELEASE_DIR/version.json"
(cd "$GEOFLOW_RELEASE_DIR" && shasum -a 256 GEOFlow-v3.0.0.zip > GEOFlow-v3.0.0.zip.sha256)
```

在任何公开标签出现前，检查 ZIP 根目录、必需文件、噪声文件、校验和及 `version.json`：

```bash
set -euo pipefail

unzip -l "$GEOFLOW_RELEASE_DIR/GEOFlow-v3.0.0.zip"
(cd "$GEOFLOW_RELEASE_DIR" && shasum -a 256 -c GEOFlow-v3.0.0.zip.sha256)
jq -e '.version == "3.0.0" and .tag == "v3.0.0" and .archive_url == "https://github.com/yaojingang/GEOFlow/releases/download/v3.0.0/GEOFlow-v3.0.0.zip"' "$GEOFLOW_RELEASE_DIR/version.json"
```

## 四、创建 Core 标签与 Draft Release

本地资产验证完成后，重新确认 `origin/main` 仍指向冻结 SHA，再创建签名标签并立即创建 Draft Release：

```bash
set -euo pipefail
: "${GEOFLOW_RELEASE_SIGNER_FINGERPRINT:?set the trusted release GPG fingerprint}"
: "${GEOFLOW_RELEASE_ACCESS_EVIDENCE_SHA256:?set the approved release access evidence SHA-256}"
[[ "$GEOFLOW_RELEASE_ACCESS_EVIDENCE_SHA256" =~ ^[a-f0-9]{64}$ ]]

git fetch origin --prune --tags
test "$(git rev-parse origin/main)" = "$GEOFLOW_RELEASE_SHA"
! git grep -nE 'contents:[[:space:]]*write' "$GEOFLOW_RELEASE_SHA" -- .github/workflows
gh api 'repos/yaojingang/GEOFlow/collaborators?affiliation=direct&per_page=100' --paginate | jq -se '
  all(.[][]; .login == "yaojingang" or (((.permissions.admin // false) == false) and ((.permissions.push // false) == false)))
'
gh api repos/yaojingang/GEOFlow/keys --paginate | jq -se 'all(.[][]; .read_only == true)'
gh api repos/yaojingang/GEOFlow/actions/permissions/workflow | jq -e '.default_workflow_permissions == "read"'
git tag -s v3.0.0 "$GEOFLOW_RELEASE_SHA" -m "GEOFlow v3.0.0"
git verify-tag --raw v3.0.0 2>&1 | grep -F "[GNUPG:] VALIDSIG $GEOFLOW_RELEASE_SIGNER_FINGERPRINT "
git push origin refs/tags/v3.0.0

GEOFLOW_REMOTE_TAG_OBJECT="$(git ls-remote origin refs/tags/v3.0.0 | awk '{print $1}')"
GEOFLOW_REMOTE_TAG_COMMIT="$(git ls-remote origin 'refs/tags/v3.0.0^{}' | awk '{print $1}')"
test "$GEOFLOW_REMOTE_TAG_OBJECT" = "$(git rev-parse 'v3.0.0^{tag}')"
test "$GEOFLOW_REMOTE_TAG_COMMIT" = "$GEOFLOW_RELEASE_SHA"

gh release create v3.0.0 --repo yaojingang/GEOFlow --verify-tag --draft --title 'GEOFlow v3.0.0' --notes-file docs/deployment/GEOFLOW_V3_RELEASE_NOTES.md "$GEOFLOW_RELEASE_DIR/GEOFlow-v3.0.0.zip" "$GEOFLOW_RELEASE_DIR/GEOFlow-v3.0.0.zip.sha256" "$GEOFLOW_RELEASE_DIR/version.json"
```

已推送的标签保持不可变。标签创建后发现源码问题时停止当前发布，并以新的补丁版本重新执行完整流程。

## 五、回读 Draft Release 构件

通过 GitHub 重新下载 Draft Release 的资产，验证服务端保存结果：

```bash
set -euo pipefail

export GEOFLOW_VERIFY_DIR="$(mktemp -d /tmp/geoflow-v3-verify.XXXXXX)"
gh release view v3.0.0 --repo yaojingang/GEOFlow --json tagName,isDraft,isPrerelease,assets --jq '
  .tagName == "v3.0.0"
  and .isDraft == true
  and .isPrerelease == false
  and ([.assets[].name] | sort) == (["GEOFlow-v3.0.0.zip", "GEOFlow-v3.0.0.zip.sha256", "version.json"] | sort)
' | grep -qx true
gh release download v3.0.0 --repo yaojingang/GEOFlow --dir "$GEOFLOW_VERIFY_DIR"
(cd "$GEOFLOW_VERIFY_DIR" && shasum -a 256 -c GEOFlow-v3.0.0.zip.sha256)
cmp "$GEOFLOW_VERIFY_DIR/GEOFlow-v3.0.0.zip" "$GEOFLOW_RELEASE_DIR/GEOFlow-v3.0.0.zip"
cmp "$GEOFLOW_VERIFY_DIR/GEOFlow-v3.0.0.zip.sha256" "$GEOFLOW_RELEASE_DIR/GEOFlow-v3.0.0.zip.sha256"
cmp "$GEOFLOW_VERIFY_DIR/version.json" "$GEOFLOW_RELEASE_DIR/version.json"
```

确认资产列表只有三项必需构件，ZIP 来自 `$GEOFLOW_RELEASE_SHA`，`version.json` 与签名 TUF 目标中的版本文档摘要一致。完成一次从 v2.3.0 备份、升级、健康检查和恢复点回滚的最终用户路径验收。

## 六、公开 Core Release 并提升 Updater 稳定目标

所有证据完成后，把 Draft Release 设为公开 Latest：

```bash
set -euo pipefail
: "${GEOFLOW_TAG_RULESET_ID:?set the protected release tag ruleset ID}"
: "${GEOFLOW_RELEASE_ACCESS_EVIDENCE_SHA256:?set the approved release access evidence SHA-256}"
[[ "$GEOFLOW_RELEASE_ACCESS_EVIDENCE_SHA256" =~ ^[a-f0-9]{64}$ ]]

gh api repos/yaojingang/GEOFlow/immutable-releases | jq -e '.enabled == true'
gh api "repos/yaojingang/GEOFlow/rulesets/$GEOFLOW_TAG_RULESET_ID" | jq -e '
  .target == "tag"
  and .enforcement == "active"
  and ((.conditions.ref_name.include | index("refs/tags/v*")) != null)
  and (([.rules[].type] | index("creation")) != null)
  and (([.rules[].type] | index("update")) != null)
  and (([.rules[].type] | index("deletion")) != null)
'
gh api 'repos/yaojingang/GEOFlow/collaborators?affiliation=direct&per_page=100' --paginate | jq -se '
  all(.[][]; .login == "yaojingang" or (((.permissions.admin // false) == false) and ((.permissions.push // false) == false)))
'
gh api repos/yaojingang/GEOFlow/keys --paginate | jq -se 'all(.[][]; .read_only == true)'
gh api repos/yaojingang/GEOFlow/actions/permissions/workflow | jq -e '.default_workflow_permissions == "read"'
! git grep -nE 'contents:[[:space:]]*write' "$GEOFLOW_RELEASE_SHA" -- .github/workflows
test "$(git ls-remote origin 'refs/tags/v3.0.0^{}' | awk '{print $1}')" = "$GEOFLOW_RELEASE_SHA"

export GEOFLOW_FINAL_VERIFY_DIR="$(mktemp -d /tmp/geoflow-v3-final-verify.XXXXXX)"
gh release download v3.0.0 --repo yaojingang/GEOFlow --dir "$GEOFLOW_FINAL_VERIFY_DIR"
cmp "$GEOFLOW_FINAL_VERIFY_DIR/GEOFlow-v3.0.0.zip" "$GEOFLOW_RELEASE_DIR/GEOFlow-v3.0.0.zip"
cmp "$GEOFLOW_FINAL_VERIFY_DIR/GEOFlow-v3.0.0.zip.sha256" "$GEOFLOW_RELEASE_DIR/GEOFlow-v3.0.0.zip.sha256"
cmp "$GEOFLOW_FINAL_VERIFY_DIR/version.json" "$GEOFLOW_RELEASE_DIR/version.json"

gh release edit v3.0.0 --repo yaojingang/GEOFlow --draft=false --latest
```

公开后立即回读稳定通道：

```bash
set -euo pipefail

gh release view v3.0.0 --repo yaojingang/GEOFlow --json tagName,isDraft,isPrerelease,publishedAt,assets,url --jq '
  .tagName == "v3.0.0"
  and .isDraft == false
  and .isPrerelease == false
  and .publishedAt != null
  and ([.assets[].name] | sort) == (["GEOFlow-v3.0.0.zip", "GEOFlow-v3.0.0.zip.sha256", "version.json"] | sort)
' | grep -qx true
curl -fsSL https://github.com/yaojingang/GEOFlow/releases/latest/download/version.json | cmp - "$GEOFLOW_RELEASE_DIR/version.json"
gh release verify v3.0.0 --repo yaojingang/GEOFlow
gh release verify-asset v3.0.0 "$GEOFLOW_FINAL_VERIFY_DIR/GEOFlow-v3.0.0.zip" --repo yaojingang/GEOFlow
gh release verify-asset v3.0.0 "$GEOFLOW_FINAL_VERIFY_DIR/GEOFlow-v3.0.0.zip.sha256" --repo yaojingang/GEOFlow
gh release verify-asset v3.0.0 "$GEOFLOW_FINAL_VERIFY_DIR/version.json" --repo yaojingang/GEOFlow
```

以上验证全部通过后结束独占发布窗口；需要恢复的协作者或应用权限按清点记录逐项恢复。

Core Release 和三个资产确认公开可读后，使用此前通过真实宿主演练的候选 run ID 与证据摘要触发 Updater 发布工作流：

```bash
set -euo pipefail

gh workflow run release.yml --repo yaojingang/geoflow-updater --ref main -f updater_version=0.3.0 -f geoflow_ref="$GEOFLOW_RELEASE_SHA" -f geoflow_version=3.0.0 -f release_sequence=2 -f candidate_run_id="$GEOFLOW_CANDIDATE_RUN_ID" -f phase_c_evidence_sha256="$GEOFLOW_PHASE_C_EVIDENCE_SHA256" -f superadmin_risk_waiver=false
```

Updater 发布工作流完成后，核对以下状态：

- `GEOFlow Updater v0.3.0` 已公开，amd64、arm64、`checksums.txt`、`bootstrap-manifest.json` 和 `publication-authorization.json` 均可下载并通过摘要校验。
- TUF 当前目标的 `release_sequence` 为 `2`、版本为 `3.0.0`、`source_commit` 等于 `$GEOFLOW_RELEASE_SHA`。
- `ghcr.io/yaojingang/geoflow-app:3.0.0` 与 `geoflow-web:3.0.0` 指向候选记录的多架构摘要。

最后在 v2.3.0 环境刷新更新缓存，确认后台提示 3.0.0 且更新工具读取序列 `2`；在 v3.0.0 环境确认状态为当前版本。发布完成后保留标签、Release 资产、候选身份、双架构演练证据、签名元数据与镜像摘要，形成完整审计链。

## 停止与修复原则

- Draft 阶段发现问题时保留草稿，修正代码后重新生成候选 SHA、Updater 构件、演练证据和 Core 资产。
- 正式 Release 公开后保持标签与资产不可变；需要修复时发布新的补丁版本和更高更新序列。
- 稳定元数据、Updater 签名目标或镜像摘要无法回读时停止升级推广，并在问题解决前保留当前稳定版本。

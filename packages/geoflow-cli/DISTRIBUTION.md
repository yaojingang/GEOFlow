# CLI 正式分发与协同发行

本目录实现正式分发入口和候选工作流。默认源码构建仍产生 schema 1 开发包；正式候选采用 schema 2，包含独立 CLI 版本、递增 `release_sequence` 和准确 `source_commit`。现有旧安装器拒绝 schema 2；新版安装器继续读取 schema 1 预览包。

本轮本地验证使用临时密钥和测试接口。正式公钥、受保护环境、公开 attestation、原生多平台安装及生产实例验收需要发布管理员完成相应门禁。

## 固定版本安装

需要 PHP 8.3+ 及 README 所列扩展、Python 3.10+、支持下列 attestation 参数的 GitHub CLI；安装平台为 macOS、Linux 和 WSL。安装器不会安装系统依赖或取得管理员权限。

先从已批准的发行记录取得 CLI 版本、源码提交、发行归档 SHA-256。CLI 标签为 `cli-v<VERSION>`，独立于 Core 标签。所有 CLI 和信任包 Release 都使用 `--latest=false`，Core 的 `releases/latest/download/version.json` 保持属于 Core。

下载 bootstrap 后，先由已有可信 `gh` 验证，再执行：

```sh
gh release download "cli-v$CLI_VERSION" --repo yaojingang/GEOFlow \
  --pattern bootstrap.py --dir ./cli-bootstrap

gh attestation verify ./cli-bootstrap/bootstrap.py --repo yaojingang/GEOFlow \
  --signer-workflow yaojingang/GEOFlow/.github/workflows/cli-sign.yml \
  --source-ref refs/heads/main --source-digest "$CLI_SOURCE_COMMIT" \
  --deny-self-hosted-runners

python3 ./cli-bootstrap/bootstrap.py install \
  --version "$CLI_VERSION" --source-commit "$CLI_SOURCE_COMMIT" \
  --sha256 "$CLI_ARCHIVE_SHA256" \
  --bin-dir "$HOME/.local/bin" --cache-dir "$HOME/.cache/geoflow-cli"
```

变量必须来自经核对的固定发行记录。此流程要求可访问 GitHub；不提供跳过证明或信任校验的开关。文档参数对应 [GitHub CLI 官方验证接口](https://cli.github.com/manual/gh_attestation_verify)。首次 bootstrap 的信任依赖本机已有可信 GitHub CLI。

bootstrap 核对标签提交、固定归档摘要、官方仓库、准确工作流、主分支 ref 和源码 SHA 后才解包。归档只允许 PHAR、manifest、签名、现有 5 个安装 PHP 文件、许可证、provenance 和 trust.json；链接、重复成员、目录穿越及额外执行文件被拒绝。通过逐项读取创建新文件，不调用未受约束的归档解压命令。

下载保留固定资产的 `.part` 文件及 ETag，重试同一命令可续传。Range 响应、对象变化、大小和完整摘要均被核对。网络下载与安装激活是两个独立阶段。

## 更新、历史回滚与事务恢复

普通升级把上述 `install` 换为 `update`。安装器持久化版本/发行序号高水位：相同发行身份重复安装无副作用；同版本不同字节或元数据、复用发行序号、普通降级均拒绝。

显式历史回滚仅接受曾安装过、现仍被当前信任包认可的本地包：

```sh
php /verified/installer/install.php --rollback \
  --bundle=/verified/historical-bundle --trusted-keys="$HOME/.local/bin/.geoflow-trust.json" \
  --bin-dir="$HOME/.local/bin"
```

`--rollback` 保留版本/序号及信任高水位。配置、token、profile 和操作日志不由分发安装器改动。操作完成后旧包仍可作为人工排查依据。

`--recover` 继续处理已持久化的未完成激活事务。它与 `--rollback`、`--update` 互斥；不表示回到旧版本。恢复重新校验包、信任和安装状态。信任撤销、过期或外部修改导致明确失败并保留事务记录。

## 官方信任及换钥

官方 trust.json 格式：schema_version=1、递增 version、UTC expires_at、keys。每个 key ID 只含 Base64 Ed25519 `public_key` 和 `status`（active/revoked）。不允许包含私钥或额外字段。

首次安装在验证整个发行归档后固定包内信任。已有安装不会从普通发行包自动替换 trust.json。已有预览安装通过下列独立路径建立官方根；日后换钥也使用此路径：

```sh
python3 ./cli-bootstrap/bootstrap.py trust \
  --version "$TRUST_VERSION" --source-commit "$TRUST_SOURCE_COMMIT" \
  --sha256 "$TRUST_SHA256" \
  --bin-dir="$HOME/.local/bin" --cache-dir="$HOME/.cache/geoflow-cli"
```

该命令限定 `cli-trust.yml` 的官方 attestation 和 `cli-trust-v<N>` 固定标签；版本倒退和同版本替换被拒绝。独立换根与安装器共用安装事务锁，先持久化 `.geoflow-trust-state.json` 高水位，再激活 `.geoflow-trust.json`。任一步写入失败都会报错；根文件激活中断后，可重试同一官方版本完成前向恢复，存档旧根无法绕过已接受的撤销。正常换钥先发布包含旧、新公钥的信任版本，再切换签名密钥，最后发布撤销旧钥的信任版本。泄露或遗失通过独立受保护信任工作流更新；禁止使用 updater TUF 密钥给 CLI 签名。

本阶段提供指定版本安装，不宣称自动发现最新版本或离线吊销查询。自动频道需要额外设计带有效期的签名索引。

## 工作流与发布管理员配置

1. 配置 `cli-trust-publication` 环境，要求人工审查、仅允许 main；设置 `CLI_TRUST_BUNDLE_B64`（公开 JSON 的 Base64）。运行 `cli-trust.yml`，明确提供根版本及 JSON SHA-256。
2. 配置独立 `cli-release-signing` 环境，要求人工审查、仅允许 main。设置 `CLI_RELEASE_KEY_B64`（Ed25519 64 字节私钥直接 Base64）、`CLI_TRUST_BUNDLE_B64`；变量设置 `CLI_RELEASE_KEY_ID`、`CLI_TRUST_SOURCE_COMMIT`。信任包必须与已发布并验证的官方 trust Release 完全一致。
3. 配置 `cli-release-publication` 环境，要求发行审查。保护 `cli-v*` 和 `cli-trust-v*` 标签，禁止覆盖和删除。密钥按独立保管、轮换和备份程序管理。
4. 在准确且干净的已提交 main 上运行 `cli-candidate.yml`。该任务无 CLI 私钥；按独立 Composer 锁构建，形成不可变 candidate.tar，使用临时签名对候选中的准确 PHAR/manifest 完成安装和接口 smoke，再生成来源证明。
5. 审阅候选测试，向 `cli-sign.yml` 提供成功候选 run ID 和准确候选 SHA-256。签名任务核对来源、固定字节、版本和独立信任根，只添加签名与信任包，不重建 PHAR。
6. 审阅签名包和外部验收，再向 `cli-release.yml` 提供 signing run ID 和发行归档 SHA-256。它验证原始证明、递增版本/序号，只上传原件，下载比对后公开 Release，始终 `--latest=false`。每次公开 draft 前都重新读取已公开版本水位，包括断点重试；已公开版本的幂等核验保留。公开后从匿名公开下载地址逐个读回完整资产并比对 SHA-256，全部通过后才报告成功。读回失败会明确报告 Release 已公开并要求重试相同版本；资产不覆盖。

候选、签名与发布首次执行要求相同准确源码提交。此阶段选择短发布窗口；源码变化后重新构建并验收，禁止绕过 SHA 校验。原候选 artifact ID、创建时间、到期时间和准确候选 SHA-256 写入签名工作流证明覆盖的 `release.json`；首次公开或草稿转公开前再次核对原候选 run 和 artifact 的准确身份及当前状态。已公开的同版本原件重试继续验证原始证明、源码身份和全部公开资产，允许在原候选到期后完成读回核验。有效期取原 artifact 到期时间与创建后 30 天的较早值，签名产物的新保留期不会延长原候选寿命。过期后重新执行候选流程。工作流及 Actions 依赖固定到提交；正式环境权限、密钥和仓库保护属于外部上线门禁。

## 四组件联合证据

`release-set.schema.json` 定义 Core、updater、CLI、skill 四个组件的准确 version、commit、sha256 和命名协议范围。摘要对应实际交付资产；可补充资产名、Core 镜像 digest、Core 发行序号和验收记录。字段不允许自动填充零摘要。

```sh
python3 -B packages/geoflow-cli/release.py release-set --input /approved/release-set.json
```

此文件作为协同发行证据单独发布或纳入验收归档；不修改 updater TUF schema 3，不触发生产更新，不建立四组件版本号相等的运行时要求。

## 本地回归

```sh
vendor/bin/phpunit packages/geoflow-cli/tests tests/Unit/GeoFlowCli/StandaloneInstallerTest.php tests/Unit/GeoFlowCli/StandaloneBuildTest.php
python3 -B -m unittest discover -s packages/geoflow-cli/tests -p 'test_*.py'
php packages/geoflow-cli/smoke.php
```

`cli-check.yml` 在 PHP 8.3 和 8.4 执行这些门禁。主发布仍需要真实公开资产、原生 macOS/Linux/WSL、实际 Core 版本对及真实密钥轮换验收；本地临时密钥测试不能替代它们。

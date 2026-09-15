# GEOFlow 独立 CLI 构建与安装

运行环境为 PHP 8.3+，需要 curl、fileinfo、mbstring、openssl、Phar 和 sodium 扩展。当前安装流程使用 POSIX 文件锁、原子重命名及目录持久化，适用于 macOS、Linux 和 WSL。

## 构建

在 GEOFlow 开发仓库执行：

```sh
php -d phar.readonly=0 scripts/build-geoflow-cli.php \
  --output=/absolute/build-output \
  --signing-key-file=/absolute/protected-signing.key \
  --key-id=trusted-release-key
```

构建只按 `packages/geoflow-cli/composer.lock` 安装独立客户端依赖，禁用 Composer 插件及脚本。签名密钥是受保护文件中的 Base64 Ed25519 私钥。未指定签名密钥时，输出仅用于开发，安装器会拒绝未签名包。

成功响应包含 `bundle`、`archive` 和 `signed`。每个完整包保存到 `bundles/<digest>/`，包含 PHAR、清单和签名；文件全部落盘后，单次原子替换 `current.json`。已有包不被覆盖。构建异常会清理本次 staging；后续构建在持有同一构建锁时清理中断留下的 staging。旧包继续保留。

## 安装与更新

从可信渠道取得本目录的 `install.php`、`StandaloneArguments.php`、`StandaloneFiles.php`、`StandaloneBundle.php`、`StandaloneInstaller.php` 及信任公钥文件。安装器本身和信任根需要先建立可信来源。信任文件格式为 `{"keys":{"key-id":"Base64 Ed25519 public key"}}`，公钥应通过独立可信渠道核验。

```sh
php /trusted/installer/install.php \
  --bundle=/absolute/build-output \
  --trusted-keys=/absolute/trusted-keys.json \
  --bin-dir=/absolute/bin
```

`--bundle` 接受构建输出根目录或具体不可变包目录。更新已有安装时增加 `--update`；自动降级会被拒绝。安装前核验 Ed25519 签名、版本格式、协议、大小及 SHA-256。安装后的 `geoflow` 为独立可执行 PHAR，可从没有 GEOFlow 源码的目录运行。

## 中断恢复

安装器先持久化完整候选包、旧版本快照和 `.geoflow-install.json` 事务记录，再替换可执行文件、清单和签名。可执行文件通过单次重命名激活；这三个文件的切换由可恢复事务协调。进程在切换期间退出时，清单可能暂时对应旧版本，事务记录会保留完整恢复依据。

```sh
php /trusted/installer/install.php \
  --recover \
  --trusted-keys=/absolute/trusted-keys.json \
  --bin-dir=/absolute/bin
```

恢复会重新核验候选签名和全部摘要，再完成原事务。重试普通安装也会先恢复未完成事务。发现信任已撤销、快照损坏或安装文件被其他操作修改时，恢复停止并保留事务记录。成功更新保留 `geoflow.previous`、`geoflow.manifest.json.previous` 及存在时的旧签名，供核验或人工恢复使用。

此目录没有正式发行签名密钥或已核验的官方信任根。测试使用临时密钥；测试包不能作为正式发布证据。

## CI 验收入口

```sh
php packages/geoflow-cli/smoke.php
```

该门禁自动生成临时测试密钥，执行真实签名构建、安装、更新，以及空目录内的 CLI 版本、帮助、登录、显式身份绑定、身份查询、站点查询和退出。远程接口由只监听本机的测试夹具提供，GEOFlow 连接环境变量不会继承到被测进程。成功后仅输出 JSON 摘要，包含包大小、摘要、版本和构建时间；失败退出码非零。临时密钥、HTTP 服务和文件在结束时清理。

门禁需要 Composer 及本机回环网络。可通过 `COMPOSER_CACHE_DIR` 复用依赖缓存；缓存完整时可附加 `COMPOSER_DISABLE_NETWORK=1` 验证离线构建。恢复故障矩阵由 `StandaloneInstallerTest` 覆盖，包含各激活阶段的进程中断、篡改、信任撤销和并发更新。

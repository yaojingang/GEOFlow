# GEOFlow Agent 示例包

这个目录提供一个最小 PHP Agent 示例，用于目标站接收 GEOFlow 后台分发的文章。

## 文件

- `php/geoflow-agent.php`：独立 PHP 入口文件，支持健康检查和文章接收。
- `php/config.example.php`：配置模板。复制为 `php/config.php` 后填写渠道密钥。

## 后台流程

1. 在 GEOFlow 后台创建分发渠道，填写目标站的 Agent 基础地址，例如 `https://www.example.com`。
2. 保存后复制 `密钥 ID` 和 `密钥明文`。如果之后忘记密钥，超级管理员可以在渠道详情页输入当前管理员密码，临时重新显示一次。
3. 在目标站部署 Agent 文件，并配置 `GEOFLOW_KEY_ID`、`GEOFLOW_SECRET`、`GEOFLOW_STORAGE_DIR`。
4. 回到渠道详情页点击“测试连接”，健康检查通过后，在任务创建或编辑页绑定该分发渠道。
5. 本地文章发布后，GEOFlow 会自动请求 `POST /geoflow-agent/v1/articles`，目标站 Agent 校验签名后保存或写入站点文章系统。

## 本地试运行

```bash
cd docs/distribution/agent-sample/php
cp config.example.php config.php
php -S 127.0.0.1:8787 geoflow-agent.php
```

然后在 GEOFlow 渠道中把 Agent 基础地址填成：

```text
http://127.0.0.1:8787
```

## 协议

GEOFlow 当前会请求两个接口：

```text
GET /geoflow-agent/v1/health
POST /geoflow-agent/v1/articles
```

每个请求都会携带：

```text
X-GEOFlow-Key-Id
X-GEOFlow-Timestamp
X-GEOFlow-Nonce
X-GEOFlow-Idempotency-Key
X-GEOFlow-Body-SHA256
X-GEOFlow-Signature
X-GEOFlow-Event
```

签名字符串为：

```text
METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + BODY_SHA256
```

签名算法为：

```text
hex_hmac_sha256(signing_string, GEOFLOW_SECRET)
```

`GET /health` 的签名 body 统一按 `{}` 计算；`POST /articles` 按真实 JSON 请求体计算。

## 生产接入建议

这个示例默认把文章 JSON 写入本地 `storage/articles/`，用于验证链路。正式目标站可以把保存逻辑替换为自己的 CMS、Laravel、WordPress 或静态站发布逻辑，但不要移除签名校验、时间窗口校验和幂等键处理。

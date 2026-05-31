# GEOAmplify 统一 CLI 调用说明

GEOAmplify 对外统一入口：

```bash
php artisan geoamplify:cli <action> --json='{}' --pretty
```

也可以使用项目内包装脚本：

```bash
bin/geoamplify-cli <action> --json='{}' --pretty
```

所有 action 都输出 JSON，外部程序只需要判断：

- `ok=true`：调用成功。
- `ok=false`：读取 `error`。
- 需要稳定结构时先调用 `schema`。

## Actions

### status

探活、读取当前数据概况和可用动作。

```bash
bin/geoamplify-cli status --pretty
```

可指定管理员：

```bash
bin/geoamplify-cli status --admin=admin --pretty
```

### schema

查看可用 action 和 payload 字段。

```bash
bin/geoamplify-cli schema --pretty
```

### diagnosis

兼容已有一次性诊断链路，内部调用 `geoamplify:geo-run`。

```bash
bin/geoamplify-cli diagnosis --json='{
  "admin": "admin",
  "organization_name": "恒森全屋定制",
  "brand_name": "恒森全屋定制",
  "products": "衣柜、橱柜、全屋定制",
  "advantages": "本地工厂、环保板材、透明计价",
  "service_area": "重庆涪陵",
  "keywords_text": "涪陵全屋定制哪家好\n重庆全屋定制推荐",
  "platform_codes": ["deepseek_mock"],
  "no_run": true
}' --pretty
```

### topic-pipeline

从选题直接跑到文章草稿、配图占位、发布包。

```bash
bin/geoamplify-cli topic-pipeline --admin=admin --json='{
  "topic": "重庆涪陵全屋定制板材环保等级怎么选",
  "platform_codes": ["ai_web_workbench:chatgpt", "ai_web_workbench:yuanbao"],
  "max_references": 2
}' --pretty
```

成功输出里重点读取：

- `draft.id`
- `draft.title`
- `publish_package.manifest_path`
- `publish_package.image_count`
- `references_count`
- `edit_url`

### submit-wxmp-draft

把已生成发布包的独立草稿提交到蚁小二微信公众号草稿箱。

```bash
bin/geoamplify-cli submit-wxmp-draft --json='{
  "draft_id": 19,
  "platform_codes": ["weixingongzhonghao"]
}' --pretty
```

该链路只提交公众号草稿，服务层保持：

- `pubType=0`
- `notifySubscribers=0`

如需只验证入参不提交：

```bash
bin/geoamplify-cli submit-wxmp-draft --json='{"draft_id":19}' --dry-run --pretty
```

### web-workbench-status

读取本机多平台 AI 网页工作台状态。

```bash
bin/geoamplify-cli web-workbench-status --json='{"limit": 5}' --pretty
```

## 退出码

- `0`：命令成功执行，继续看 JSON 的 `ok`。
- `2`：入参不合法或必填项缺失。
- `1`：运行时异常，例如外部服务不可用、引用源不足、蚁小二提交失败。

## 外部程序建议

1. 先调用 `status` 确认项目可访问。
2. 需要字段契约时调用 `schema`。
3. 调用业务 action 时始终传 JSON，不依赖人类可读文本。
4. 发布动作默认只走 `submit-wxmp-draft`，不要绕过 `pubType=0` 和 `notifySubscribers=0`。

## GUI 验收截图

完整提交说明和 GUI 截图见：

- [2026-05-31 GEOAmplify CLI 与 GUI 验收说明](release-notes/2026-05-31-geoamplify-cli-and-gui-release.md)

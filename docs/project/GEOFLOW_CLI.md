# GEOFlow CLI 使用说明

> 📖 **For English users, please refer to [GEOFLOW_CLI_en.md](GEOFLOW_CLI_en.md).**

`geoflow` 是第一阶段 API 的本地命令行入口。

它只通过正式 `/api/v1` 与系统通信，不直接访问数据库，也不复用后台 session。

---

## 1. 命令入口

项目内命令入口：

```bash
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow help
```

也可以给脚本执行权限后直接运行：

```bash
/Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow help
```

---

## 2. 配置方式

支持三种方式提供连接信息：

1. CLI 参数
2. 环境变量
3. 配置文件

优先级：

`CLI 参数 > 环境变量 > .geoflow.json > ~/.config/geoflow/config.json`

### 2.1 推荐登录

推荐优先使用管理员账号密码完成首次登录，CLI 会自动换取 token 并保存本地配置：

```bash
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow \
  login \
  --base-url http://127.0.0.1:18080 \
  --username admin
```

如果不传 `--password`，CLI 会在终端里安全提示输入密码。

### 2.2 手动初始化

如果你已经有可用 token，也可以手动初始化：

```bash
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow \
  config init \
  --base-url http://127.0.0.1:18080 \
  --token gf_xxx
```

默认会写入：

```text
~/.config/geoflow/config.json
```

配置文件格式：

```json
{
  "base_url": "http://127.0.0.1:18080",
  "token": "gf_xxx",
  "timeout": 30
}
```

### 2.3 查看当前配置

```bash
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow config show
```

### 2.4 临时覆盖

```bash
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow \
  --config /tmp/geoflow.json \
  catalog
```

---

## 3. 常用命令

### 3.1 获取资源字典

```bash
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow catalog
```

### 3.0 首次登录示例

```bash
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow \
  login \
  --base-url http://127.0.0.1:18080 \
  --username admin
```

或者显式传密码：

```bash
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow \
  login \
  --base-url http://127.0.0.1:18080 \
  --username admin \
  --password your-password
```

### 3.2 任务管理

查询任务：

```bash
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow task list --status active
```

创建任务：

```bash
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow task create --json ./task.json
```

启动任务：

```bash
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow task start 12
```

立即入队：

```bash
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow task enqueue 12
```

查看任务 job：

```bash
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow task jobs 12 --limit 20
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow job get 88
```

### 3.3 文章管理

直接上传文章草稿：

```bash
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow article create \
  --title "CLI 测试文章" \
  --content-file ./article.md \
  --task-id 12 \
  --author-id 5 \
  --category-id 2
```

通过 JSON 创建：

```bash
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow article create --json ./article.json
```

审核通过：

```bash
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow article review 101 \
  --status approved \
  --note "CLI review pass"
```

发布文章：

```bash
php /Users/laoyao/AI\ Coding/01-Projects/Active/GEO官网系统/bin/geoflow article publish 101
```

---

## 4. JSON 输入

### 4.1 任务创建示例

```json
{
  "name": "CLI Task Integration Test",
  "title_library_id": 1,
  "prompt_id": 6,
  "ai_model_id": 1,
  "knowledge_base_id": 1,
  "author_id": 5,
  "image_library_id": null,
  "image_count": 0,
  "need_review": true,
  "publish_interval": 3600,
  "auto_keywords": true,
  "auto_description": true,
  "draft_limit": 3,
  "is_loop": false,
  "status": "paused",
  "category_mode": "smart",
  "fixed_category_id": null
}
```

### 4.2 文章创建示例

```json
{
  "title": "CLI Article Test",
  "content": "# CLI Article Test\n\n这是通过 CLI 创建的文章。",
  "task_id": 12,
  "author_id": 5,
  "category_id": 2,
  "status": "draft",
  "review_status": "pending",
  "keywords": "cli,test",
  "meta_description": "CLI article integration test"
}
```

---

## 5. 幂等键

所有写操作都支持：

```text
--idempotency-key <key>
```

推荐在这些命令里使用：

- `task create`
- `task update`
- `task start`
- `task stop`
- `task enqueue`
- `article create`
- `article update`
- `article review`
- `article publish`
- `article trash`

这样 CLI 或后续 skill 在重试时不会重复创建资源。

---

## 6. 当前支持范围

当前 CLI 已覆盖第一阶段 API 主链路：

- `login`
- `catalog`
- `task list/create/get/update/start/stop/enqueue/jobs`
- `job get`
- `article list/create/get/update/review/publish/trash`

当前还没有纳入：

- URL 导入
- 标题异步生成
- 图片上传编排
- 批量工作流封装

这些更适合在下一阶段通过 skill 再做一层高阶封装。

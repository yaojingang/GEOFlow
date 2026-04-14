# GEOFlow FAQ

> Languages: [简体中文](FAQ.md) | [English](FAQ_en.md) | [日本語](FAQ_ja.md) | [Español](FAQ_es.md) | [Русский](FAQ_ru.md)

## 1. 默认后台地址和账号是什么？

- 后台地址：`/geo_admin/`
- 默认管理员用户名：`admin`
- 默认管理员密码：`admin888`

首次登录后，建议立刻修改管理员密码和 `APP_SECRET_KEY`。

## 2. 必须用 Docker 吗？

不是。

你可以：

- 使用 Docker Compose 启动 `web + postgres + scheduler + worker`
- 或者在本地安装 PHP 和 PostgreSQL 后直接运行 `php -S`

如果你只是想尽快跑起来，优先用 Docker。

## 3. 运行时必须使用 PostgreSQL 吗？

是。

当前公开版本的正式运行时数据库是 PostgreSQL。仓库里不会附带生产数据库文件。

## 4. 为什么仓库里没有图片库、知识库和文章数据？

因为这些都属于运行数据或业务数据：

- 图片库
- 知识库原始文件
- 已生成文章
- 日志和备份

公开仓库只提供源码和配置模板，不附带这些内容。

## 5. AI 模型如何接入？

进入后台后，到“AI 配置中心 → AI 模型管理”添加模型，填写：

- API 地址
- 模型 ID
- Bearer Token

系统兼容 OpenAI 风格接口。

## 6. 文章生成链路是什么？

基本流程是：

1. 配置模型、提示词和素材库
2. 创建任务
3. 调度器入队
4. Worker 执行 AI 生成
5. 草稿 / 审核 / 发布
6. 前台展示文章

## 7. 有没有 CLI 或 skill？

有。

- CLI 说明见：[project/GEOFLOW_CLI.md](project/GEOFLOW_CLI.md)
- 配套 skill 仓库：[yaojingang/yao-geo-skills](https://github.com/yaojingang/yao-geo-skills)
- 对应 skill：`skills/geoflow-cli-ops`

## 8. 哪些文档适合二次开发时优先阅读？

优先看这几份：

- [系统说明文档](系统说明文档.md)
- [AI 开发指南](AI_PROJECT_GUIDE.md)
- [项目结构说明](project/STRUCTURE.md)
- [API v1 参考草案](project/API_V1_REFERENCE_DRAFT.md)

# 常见问题

## 1. 默认后台地址和账号是什么？

- 后台地址：`/geo_admin/`
- 默认管理员用户名：`admin`
- 默认管理员密码：`admin888`

首次登录后，建议立刻修改管理员密码和 `APP_SECRET_KEY`。

## 2. 必须使用 Docker 吗？

不是。

你可以：

- 用 Docker Compose 启动 `web + postgres + scheduler + worker`
- 或者在本地安装 PHP 与 PostgreSQL 后直接运行

如果目标是尽快跑起来，Docker 是更稳的方式。

## 3. 运行时数据库必须是 PostgreSQL 吗？

是。

当前公开版本的正式运行时数据库是 PostgreSQL。  
仓库不会附带生产数据库或示例业务数据。

## 4. 为什么公开仓没有图片库、知识库和文章数据？

因为这些都属于运行时或业务数据，例如：

- 图片库内容
- 知识库原始文件
- 已生成文章
- 日志和备份

公开仓只提供源码和配置模板，不附带这些内容。

## 5. 如何接入 AI 模型？

进入后台：

`AI 配置中心 -> AI 模型管理`

填写：

- API 地址
- 模型 ID
- Bearer Token

系统兼容 OpenAI 风格接口，并已经对部分 provider 的版本化路径做了兼容。

## 6. 文章生成链路是什么？

基本流程是：

1. 配置模型、提示词和素材库
2. 创建任务
3. 调度器入队
4. Worker 执行 AI 生成
5. 草稿 / 审核 / 发布
6. 前台输出文章页面

## 7. 有没有 CLI 或配套 Skill？

有。

- CLI 说明见项目文档中的 `GEOFLOW_CLI`
- 配套 skill 仓库：[yaojingang/yao-geo-skills](https://github.com/yaojingang/yao-geo-skills)
- 当前已公开：
  - `geoflow-cli-ops`
  - `geoflow-template`

## 8. 什么情况下适合用 GEOFlow？

比较适合：

- 独立 GEO 官网
- 官网 GEO 子频道
- GEO 信源站
- 内部 GEO 内容管理后台
- 多站点、多频道的内容协同

如果只是想批量制造低质量页面，这套系统不适合。

## 9. 为什么强调知识库优先？

因为 GEOFlow 的价值建立在真实、优质、可维护的知识资产之上。  
如果知识本身不稳定，自动化只会放大噪音。

## 10. 建议先读哪些页面？

推荐顺序：

1. [快速上手](Getting-Started.md)
2. [什么是 GEOFlow](What-Is-GEOFlow.md)
3. [GEOFlow 方法论](GEOFlow-Methodology.md)
4. [使用边界与内容底线](Principles-and-Content-Boundaries.md)
5. [适用场景](Use-Cases.md)

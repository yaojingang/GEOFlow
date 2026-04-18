# GEOFlow 更新日志

该文档记录公开仓库可见功能的持续更新。后续每次推送到 GitHub 时，同步更新本文件和英文版 `CHANGELOG_en.md`。

## 2026-04-18

### v1.2

- 新增后台与前台第一阶段中英界面支持：
  - 后台正式管理页支持中英切换
  - 登录页支持独立语言选择
  - 前台公共壳子跟随后台语言显示
- 新增任务 `智能模型切换`：
  - 任务支持 `固定模型` 与 `智能模型切换`
  - 主模型失败时，系统按模型优先级自动尝试下一个可用聊天模型
- 优化模型接入规则：
  - 支持 OpenAI、DeepSeek、MiniMax、智谱 GLM、火山方舟等不同版本化 chat / embedding endpoint
  - 后台模型配置支持基础地址或完整接口
- 优化任务执行体验：
  - `task-execute.php` 改为入队执行，不再同步阻塞页面
  - 直接发布任务的 `published_count` 统计已修正
- 新增前台模板预览与启用能力：
  - 支持独立 `preview/<theme-id>` 动态预览路由
  - 支持主题包 `themes/<theme-id>` 结构
  - 后台网站设置支持模板预览与启用
  - 样板主题 `qiaomu-editorial-20260418` 已进入公开仓库
  - 首页、分类页、归档页卡片摘要会自动清洗 Markdown 符号
- 新增 `geoflow-template` 配套 skill 入口：
  - 用于把参考网址映射为 GEOFlow 兼容主题包
  - 支持输出 `tokens.json`、`mapping.json` 和 preview-first 模板规划
- 升级默认 GEO 提示词：
  - 正文、榜单、关键词、描述提示词更新为长版模板
  - 对齐 GeoFlow 变量规则
- 修复若干后台可用性问题：
  - 数据库时区偏差
  - 文章图片路径缺少前导 `/`
  - 标题 AI 保存时的 PostgreSQL 布尔类型写入错误
  - Provider 默认示例从旧的第三方域名改为更中性的 DeepSeek

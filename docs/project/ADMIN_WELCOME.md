# 后台首次登录欢迎页

## 页面结构稿

欢迎页采用后台首次登录后的全屏弹层，不改后台主导航、权限逻辑和页面布局，只在当前页面上方覆盖一层介绍面板。页面形式不再是多卡片模块，而是一篇单模块的“见面信”。

### 结构分区

1. 顶部操作区
- 徽标：`写在开始之前`
- 语言按钮：`English`
- 关闭按钮：`关闭`

2. 单篇欢迎信主体
- 标题：`欢迎使用 GEOFlow`
- 副标题：`你好，欢迎来到 GEOFlow。`
- 正文：按文章阅读流组织，而不是模块卡片
- 内容顺序：
  - 先打招呼
  - 再解释系统定位
  - 再说明能做什么、适用场景、设计逻辑
  - 再说明后续规划与作者介绍

3. 底部联系区
- 说明文字
- `作者 X 主页`
- `项目 GitHub`
- `更新日志`

4. 重开入口
- 后台 footer 增加 `项目说明`
- 点击后重新打开欢迎页，不重置首次关闭状态

## 字段 key 清单

欢迎页内容使用结构化 key 组织，便于后续继续拆到 i18n 或 CMS 配置中。

### Meta
- `meta.badge`
- `meta.switch_label`
- `meta.close`
- `meta.links_label`
- `meta.author_link`
- `meta.github_link`
- `meta.changelog_link`

### Letter
- `letter.title`
- `letter.subtitle`
- `letter.blocks[]`
- `letter.blocks[].type`
- `letter.blocks[].content`
- `letter.blocks[].items[]`

### Footer reopen entry
- `footer.project_intro_link`

## 后台接入逻辑

### 触发规则
- 管理员首次登录后台后自动弹出
- 一旦关闭，后续不再自动弹出
- footer 中的 `项目说明` 可随时手动打开

### 持久化方式
- `admins.welcome_dismissed_at`
- 为 `NULL` 时表示尚未关闭过
- 关闭欢迎页后更新为 `CURRENT_TIMESTAMP`

### 接入点
- `admin/index.php`
  - 登录成功后沿用原有逻辑跳转，不在这里插入介绍页
- `admin/includes/footer.php`
  - 渲染欢迎页弹层
  - 添加 `项目说明` 重开按钮
- `admin/welcome-dismiss.php`
  - 处理关闭后的持久化写入

### 语言逻辑
- 欢迎页默认中文显示
- 弹层右上角可切到英文
- 该切换只影响欢迎页本身，不改后台全局语言

### 安全边界
- 不修改后台权限
- 不改现有登录流程
- 不改现有页面路由
- 关闭接口要求管理员已登录且通过 CSRF 校验

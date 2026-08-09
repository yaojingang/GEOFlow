# GEOFlow Admin UI V2 原型包

这是一套独立、只读、使用演示数据的 GEOFlow 后台原型，共包含 84 个页面状态。

## 访问方式

推荐在 GEOFlow 项目根目录执行：

```bash
php -S 127.0.0.1:4173 -t public
```

然后访问：

```text
http://127.0.0.1:4173/previews/geoflow-admin-ui-v2/index.html
```

页面文件也可以直接双击打开。原型不使用接口请求、数据库或远程资源。

## 主要入口

- `index.html`：全部 84 个页面目录
- `component-gallery.html`：公共组件和样式检查
- `qa-matrix.html`：页面、状态和响应式检查矩阵
- `manifest.json`：页面、路由、角色和文件映射
- `design-lock.json`：两个基准原型的锁定规则

## 修改页面内容

页面名称、文案、路由映射、演示关键词和页面类型统一维护在：

```text
scripts/page-definitions.mjs
```

路由标签使用 `{adminBasePath}` 表示 Laravel 可配置的 `geoflow.admin_base_path`，原型不假定固定的 `/admin` 路径。

修改后重新生成：

```bash
node public/previews/geoflow-admin-ui-v2/scripts/build.mjs
```

生成后执行完整性校验：

```bash
node public/previews/geoflow-admin-ui-v2/scripts/verify.mjs
```

校验会检查 84 个页面清单、路由和文件映射、公共资源、设计基线锁、表单与标签页交互钩子，以及禁止空链接等规则。人工复核范围记录在 `qa/review-checklist.json`，覆盖 1440、1280、768、375 和 320 五档宽度。

## 修改公共 UI

- 公共样式：`assets/css/prototype.css`
- 公共侧栏和顶部栏：`assets/js/geoflow-shell.js`
- 页面组件：`assets/js/geoflow-components.js`
- 交互逻辑：`assets/js/interactions.js`
- 页面启动：`assets/js/app.js`

公共壳层和组件只维护一份，所有页面会同步使用更新后的代码。

## 演示状态

任意后台页面可以追加状态参数：

```text
?state=empty
?state=loading
?state=error
?state=permission
```

例如：

```text
pages/content/tasks-index.html?state=empty
```

## 原型边界

- 不会调用生产接口
- 不会修改数据库
- 不会执行真实分发、升级、删除或发布
- 不替换现有 Blade 页面
- 27 套前台主题不在本原型包范围内

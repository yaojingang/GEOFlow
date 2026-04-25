## Stage 1: 深入路由与骨架落地
**Goal**: 补齐 5 个模块 detail 入口、控制器方法、Blade 骨架，打通主链路。
**Success Criteria**: 各模块 index 可进入 detail，detail 页面可访问且不影响既有 index/form。
**Tests**: 访客重定向；管理员访问 detail 与入口链接断言。
**Status**: Complete

## Stage 2: 关键词库/标题库 detail 深入功能
**Goal**: 完成关键词与标题 detail 的搜索、分页、新增、删除、批量与导入，补标题 AI 生成。
**Success Criteria**: 两个 detail 页面形成完整管理闭环。
**Tests**: detail 功能成功/失败路径、导入去重、AI 生成最小回归。
**Status**: Complete

## Stage 3: 图片库/知识库 detail 深入功能
**Goal**: 完成图片上传/批量删除/预览与知识库上传/切块展示/引用保护。
**Success Criteria**: 图片与知识库 detail 功能与 bak 主流程一致。
**Tests**: 上传删除（DB+文件）、chunk 同步、引用阻断。
**Status**: Complete

## Stage 4: 作者模块与 materials 总页交互补齐
**Goal**: 完成作者分页细节与 materials 页交互收口。
**Success Criteria**: 作者分页与删除提示对齐，materials 入口交互一致。
**Tests**: 作者分页与删除提示，materials 链接断言。
**Status**: Complete

## Stage 5: 全量回归与交付
**Goal**: 完成格式化、测试、文案占位符统一、迁移进度更新。
**Success Criteria**: pint 通过，相关测试通过，文案渲染正确。
**Tests**: 受影响 Feature 回归。
**Status**: Complete

## Stage 6: 知识库列表页 1:1 对齐补齐
**Goal**: 对齐 `geo_admin/knowledge-bases.php` 的列表页结构、空态、模态框与动作入口。
**Success Criteria**: 知识库列表页的统计卡、列表项徽章、创建/上传模态框、chunk 按钮和向量配置提示与 bak 主流程一致。
**Tests**: 手工验证列表展示、创建知识库、上传文档、删除、无向量模型提示链路。
**Status**: Complete

## Stage 7: 作者管理列表页 1:1 对齐补齐
**Goal**: 对齐 `geo_admin/authors.php` 的列表布局、模态交互与分页展示。
**Success Criteria**: 作者列表页统计卡、搜索栏、作者卡、创建/编辑模态框、删除确认与分页交互与 bak 主流程一致。
**Tests**: 手工验证搜索、创建、编辑、删除（含回收站提示）、分页切换。
**Status**: Complete

## Stage 8: AI 配置器首页 1:1 对齐补齐
**Goal**: 对齐 `geo_admin/ai-configurator.php` 的首页布局、模块导航与配置概览。
**Success Criteria**: AI 配置器首页卡片、概览统计、帮助说明与 bak 主流程一致；模块入口跳转到新路由。
**Tests**: 手工验证首页展示、统计数值展示、模块入口链接可访问。
**Status**: Complete

## Stage 9: AI 模型配置页 1:1 对齐补齐
**Goal**: 对齐 `geo_admin/ai-models.php` 的列表、模型弹窗与默认 embedding 模型配置流程。
**Success Criteria**: AI 模型列表、向量状态卡、模型新增/编辑/删除、默认 embedding 设置与 bak 主流程一致。
**Tests**: 手工验证模型增删改、默认 embedding 保存、任务引用删除阻断。
**Status**: Complete

## Stage 10: Worker -> Laravel Queue 迁移设计落地
**Goal**: 将 `bak/bin/worker.php` 的执行链改为 Laravel Job 驱动，保留 `job_queue` 业务表状态流转。
**Success Criteria**: 新建 pending job 后会自动 dispatch Laravel 队列任务，不再依赖常驻 `geoflow:worker` 扫描循环。
**Tests**: 路由触发入队后检查 `jobs`/`job_queue` 状态变化。
**Status**: Complete

## Stage 11: 失败重试与状态回写对齐
**Goal**: 把失败重试（pending 重排）与完成/失败/取消回写逻辑迁到队列 Job 执行路径。
**Success Criteria**: `completeJob` / `failJob` / `cancelJob` 在 Laravel Job 路径可用，重试任务可再次被 dispatch。
**Tests**: 人工触发失败后检查 `attempt_count`、`available_at`、后续执行。
**Status**: Complete

## Stage 12: 心跳与管理后台兼容
**Goal**: 保持 `worker_heartbeats` 与任务页运行面板可读，兼容队列 worker 场景。
**Success Criteria**: 任务页仍能看到运行状态与最近 job 信息，字段格式不破坏现有 Blade。
**Tests**: 手工打开任务页验证运行面板字段展示。
**Status**: Complete

## Stage 13: 最小回归与收口
**Goal**: 完成格式化、路由/语法校验与计划状态收口。
**Success Criteria**: pint、lint 通过；关键路径无语法与路由回归。
**Tests**: `pint` + `route:list` + 受影响文件 lint。
**Status**: Complete

## Stage 14: 移除 job_queue 运行依赖
**Goal**: 停用 `job_queue` 作为运行主链路，改为 `Laravel Queue + Redis` + `task_runs` 执行记录。
**Success Criteria**: 入队/执行/失败重试/后台统计不再读取 `job_queue`；`geoflow:worker` 明确弃用。
**Tests**: 语法检查、`pint`、`AdminTasksPageTest`、受影响文件 lint。
**Status**: Complete

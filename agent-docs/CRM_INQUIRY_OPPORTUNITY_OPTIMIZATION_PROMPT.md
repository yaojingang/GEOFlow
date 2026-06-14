# CRM 询盘与商机边界优化提示词

本提示词用于后续优化 GEOFlow 轻量 CRM 中“询盘”和“商机”的职责边界。执行前先读：

- `agent-docs/AGENT_BRIEF.md`
- `agent-docs/DOC_READ_POLICY.md`
- `功能说明文档/10-轻量CRM与报价使用说明.md`
- 涉及文件：`app/Models/CrmInquiry.php`、`app/Models/CrmOpportunity.php`、`app/Http/Controllers/Admin/CrmInquiryController.php`、`app/Http/Controllers/Admin/CrmOpportunityController.php`、`resources/views/admin/crm/inquiries/*`、`resources/views/admin/crm/opportunities/*`、`resources/views/admin/crm/quotes/form.blade.php`

## 执行状态

已按阶段 1-4 完成核心实现：

- 询盘状态收敛为需求处理状态，并兼容历史 `quoted / won / lost`。
- 询盘详情页支持一键转商机，成功后来源询盘自动标记为 `converted`。
- 商机页展示来源询盘上下文、关联单据列表和“新建单据”入口。
- 单据创建/编辑/详情/列表支持 `opportunity_id` 关联。

未执行阶段 5 历史数据整理工具。该阶段会批量影响旧询盘与商机管道统计，后续需单独确认。

## 背景判断

当前系统中“询盘”和“商机”存在职责重叠：

- 询盘状态包含 `qualified / quoted / won / lost`。
- 商机阶段也包含 `qualified / proposal / won / lost`。
- 因此询盘开始承担销售管道职责，商机又承担成交推进职责，前端用户容易不知道应该在哪个模块操作。

但两者不应该简单合并：

- 询盘是客户原始需求入口，负责保存客户说了什么、来源、AI 分析、关联 Entity / Knowledge / Case。
- 商机是销售机会管道，负责保存金额、概率、阶段、下一步、报价、赢单/输单。
- 当前数据库已有 `crm_opportunities.source_inquiry_id`，说明系统已经具备兼容关系，不需要强行把所有询盘迁移进商机。

## 总目标

把 CRM 流程整理为：

`客户原始需求 -> 询盘分析 -> 确认有效 -> 转为商机 -> 报价/合同/订单 -> 售后`

优化时必须做到：

- 保留现有询盘数据，不做破坏性迁移。
- 保留 `crm_opportunities.source_inquiry_id` 作为询盘到商机的桥接。
- 不删除旧状态数据；前端可以弱化旧状态，但要兼容已有记录。
- 不破坏报价、订单、售后与询盘的现有关联。
- 所有新增 UI 复用现有 CRM 卡片、表单、按钮、`admin.crm.partials.nav`、`task-form`、`task-row` 风格。

## 非目标

本阶段不做：

- 完整 ERP 销售漏斗重构。
- CRM 自动销售预测。
- 把所有询盘强制转成商机。
- 删除 `crm_inquiries` 表。
- 删除已有询盘状态字段。
- 删除报价单的 `inquiry_id`。

## 阶段 1：询盘状态边界收敛

### 目标

让询盘只表达“需求处理状态”，不再承担成交管道状态。

### 建议状态

前端新建/编辑询盘只显示：

- `new`：新询盘
- `analyzing`：分析中
- `qualified`：已确认
- `converted`：已转商机
- `invalid`：无效询盘
- `closed`：关闭

### 兼容旧状态

数据库和后端仍兼容旧值：

- `quoted`
- `won`
- `lost`

旧值在列表和详情页显示为“历史状态”，不要直接报错。

### 前端增减项

新增：

- 询盘详情页状态说明：提示“报价、赢单、输单请在商机中管理”。
- 询盘列表可筛选 `converted` 和 `invalid`。
- 旧状态 badge 显示为灰色或 amber，标注“历史”。

减少：

- 新建/编辑询盘表单中不再主动展示 `quoted / won / lost` 作为可选项。

### 冲突检查

- 检查 `CrmInquiryController::validateInquiry()` 是否允许新状态和旧状态。
- 检查 `resources/views/admin/crm/inquiries/index.blade.php`、`form.blade.php`、`show.blade.php` 状态映射是否一致。
- 检查 `AdminCrmPagesTest` 中是否有依赖旧状态选项的断言。

## 阶段 2：询盘转商机流程增强

### 目标

把“转为商机”从简单跳转优化为明确的一次业务转化。

### 后端逻辑

从询盘创建商机时自动带入：

- `collection_id`
- `customer_id`
- `source_inquiry_id`
- `name`：默认使用询盘标题
- `owner_admin_id`：如果能从 `assigned_to` 对应到 admin，则带入；无法对应则留空
- `stage`：默认 `qualified`
- `notes`：合并询盘的 `customer_need_summary`、`product_interest`、`missing_information_questions` 和 `notes`

商机创建成功后：

- 如果来源询盘不是旧 `won/lost/closed`，自动更新询盘状态为 `converted`。
- 询盘详情页显示已关联商机列表。
- 已有商机时，“转为商机”按钮改为“查看商机”，避免重复创建。

### 前端增减项

新增：

- 询盘详情页顶部增加“关联商机”卡片。
- 如果无商机，显示主按钮“转为商机”。
- 如果已有商机，显示商机名称、阶段、金额、下一步，并提供“查看商机”。
- 商机创建页从询盘进入时，在右侧显示“来源询盘摘要”。

减少：

- 询盘详情页不要再把 `won/lost` 当作主要操作结果。

### 冲突检查

- 不要删除询盘已有 `quotes`、`salesOrders` 关联展示。
- 如果询盘已软删除，商机的 `source_inquiry_id` 应继续兼容 `nullOnDelete`。
- 如果一个询盘允许多个商机，需要 UI 明确显示；推荐默认只鼓励一个主商机，但不强制数据库唯一，避免破坏现有数据。

## 阶段 3：商机页面补齐来源上下文

### 目标

让商机真正承接询盘分析结果，而不是只保存金额和阶段。

### 前端新增

商机编辑页侧边栏增加“来源询盘上下文”卡片：

- 来源询盘标题
- 客户原始需求摘要
- 产品兴趣
- 缺失信息问题
- 关联 Entity 数量
- 关联知识库数量
- 关联 Case 数量
- 链接到询盘详情

商机编辑页主表单保持简洁，只管理销售推进字段：

- 阶段
- 金额
- 概率
- 预计成交日期
- 下一步动作
- 下一步时间
- 竞争对手
- 输单原因
- 备注

### 可复用样式

- 复用 `resources/views/admin/crm/partials/task-form.blade.php`
- 复用 `resources/views/admin/crm/partials/task-row.blade.php`
- 复用 CRM show 页里的 `rounded-lg border border-gray-200 bg-white p-5 shadow-sm`
- 输入框使用 GEOFlow UI skill 中的统一 border / focus 样式

### 冲突检查

- 不要把 Entity / Knowledge / Case 多选重新搬到商机表单里，避免和询盘分析职责重叠。
- 如果商机需要参考 Entity / Knowledge / Case，应从来源询盘只读展示，不在商机重复维护。

## 阶段 4：报价单与商机联动

### 目标

报价单既可以从询盘创建，也可以从商机创建。商机下的报价更适合进入订单转换。

### 后端建议

`crm_quotes` 已有 `opportunity_id` 字段，补齐表单使用：

- 报价表单新增“关联商机”选择器。
- 选择客户后，商机选项限制为该客户。
- 选择 Collection 后，商机选项限制为该 Collection。
- 如果从商机创建报价，自动带入 `opportunity_id`、`customer_id`、`collection_id`，并保留 `inquiry_id = source_inquiry_id`。

### 前端新增

- 商机编辑页增加“新建单据”按钮。
- 商机编辑页显示关联单据列表。
- 报价表单基础信息区增加“关联商机”下拉。
- 报价详情页同时显示关联询盘和关联商机。

### 前端减少

- 不要让询盘详情页承担完整报价推进视图；询盘可以显示关联报价，但销售推进应鼓励进入商机。

### 冲突检查

- 保留现有从询盘创建报价的入口，避免破坏用户旧流程。
- `CrmQuoteController::formData()` 需要补充 opportunity options，但不能让页面一次性加载过多数据；限制 Collection/客户范围。
- `CrmQuote::opportunity()` 关系如缺失应补齐。

## 阶段 5：可选历史数据整理工具

### 目标

提供安全的历史数据整理方案，但不默认自动迁移。

### 建议方案

新增一个 Artisan 命令或后台只读报告：

`crm:inquiries-opportunity-audit`

功能：

- 扫描状态为 `quoted / won / lost` 且没有商机的询盘。
- 生成建议清单，不直接写入。
- 支持 `--dry-run` 默认模式。
- 只有显式传入 `--create-opportunities` 才批量创建商机。

### 映射建议

- `quoted` -> 商机 `proposal`
- `won` -> 商机 `won`
- `lost` -> 商机 `lost`

创建商机时：

- `source_inquiry_id = inquiry.id`
- `name = inquiry.subject`
- `customer_id = inquiry.customer_id`
- `collection_id = inquiry.collection_id`
- `notes` 带入询盘摘要

### 风险

该阶段必须单独确认后执行，因为批量创建商机会改变商机管道统计。

## 阶段 6：文档、测试和回归

### 必测项

- 新询盘可以创建、编辑、归档。
- 旧状态 `quoted/won/lost` 的询盘仍可打开详情和编辑保存。
- 从询盘创建商机后，商机带入来源信息。
- 询盘状态自动变为 `converted`。
- 已有商机时，询盘详情页不鼓励重复创建。
- 从商机创建报价时，报价关联 `opportunity_id`，并可保留来源 `inquiry_id`。
- 报价、订单、售后现有测试不受影响。

### 推荐测试文件

- `tests/Feature/AdminCrmPagesTest.php`

### 推荐命令

```bash
docker exec geoflow-app php -l app/Http/Controllers/Admin/CrmInquiryController.php
docker exec geoflow-app php -l app/Http/Controllers/Admin/CrmOpportunityController.php
docker exec geoflow-app php -l app/Http/Controllers/Admin/CrmQuoteController.php
KEY=$(docker exec geoflow-app sh -lc "grep '^APP_KEY=' /var/www/html/.env | cut -d= -f2-")
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test tests/Feature/AdminCrmPagesTest.php --stop-on-failure
```

### 必须视觉检查

至少检查：

- `/admin/crm/inquiries`
- `/admin/crm/inquiries/{id}`
- `/admin/crm/opportunities`
- `/admin/crm/opportunities/{id}/edit`
- `/admin/crm/quotes/create`

检查点：

- 没有横向溢出。
- 商机管道卡片没有被长文本撑破。
- 询盘详情页的“转为商机 / 查看商机”入口明确。
- 商机来源询盘卡片不与待办卡片重复。
- 报价表单基础信息区没有过度拥挤。

## 建议执行顺序

1. 先做阶段 1：状态边界收敛。
2. 再做阶段 2：询盘转商机增强。
3. 再做阶段 3：商机来源上下文展示。
4. 再做阶段 4：报价单与商机联动。
5. 阶段 5 历史整理工具先只做报告，不自动批量创建。
6. 最后更新功能说明文档和 `agent-docs/IMPLEMENTATION_STATUS.md`。

## 给执行 agent 的额外要求

- 遇到是否批量迁移历史询盘到商机的问题，必须停下来问用户。
- 不要删除旧询盘状态，只能前端弱化和后端兼容。
- 不要把 Entity / Knowledge / Case 维护入口复制到商机里。
- 不要让商机替代询盘保存客户原始消息。
- 不要让询盘继续承担赢单/输单主流程。
- 所有 UI 改动遵守 `geoflow-ui-guidelines` skill。
- 所有 Laravel 测试遵守 `geoflow-testing` skill，尤其注意 Docker `APP_KEY`。

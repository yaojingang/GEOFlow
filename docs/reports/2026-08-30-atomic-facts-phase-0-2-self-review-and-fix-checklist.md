# 原子事实库 Phase 0 至 Phase 2 自检与修复清单

日期：2026-08-30

审查基线：`c4c67a4c..9a7acf91`

审查范围：Phase 0 评测基线、Phase 1 原子事实库、Phase 2 AI 生成与管理端操作面

## 审查结论

本轮按高风险数据变更做了深度审查，覆盖权限、输入可信度、并发写入、队列重试、发布版本、证据失效、评测口径、管理端可操作性和数据保留。共确认 27 项代码问题，均已完成修复和自动化验证。独立人工标注集与生产质检接入继续保留为两项上线门禁。

当前实现可以继续用于 Phase 0 离线评测、Phase 1 事实库维护和 Phase 2 受控生成。生产质检切换仍保持关闭，解锁条件是独立人工标注集和真实模型端到端评测达到门槛。

## 已修复清单

| 编号 | 等级 | 自检发现 | 修复结果 | 验证 |
|---|---|---|---|---|
| 01 | 阻断 | Comparator contract 只检查 JSON 结构，没有执行比较算法 | 新增确定性 `AtomicFactComparator`，250 条契约逐条执行并核对结果 | CLI 契约测试通过 |
| 02 | 高 | 原子事实误拦截率误用了全体安全样本分母 | 改为只统计带 `atomic_fact` 的安全样本 | 新增混合样本回归测试 |
| 03 | 高 | 合成黄金集写了“双人标注与裁决”，与数据生成方式不符 | 数据说明改为确定性合成协议样本，明确独立人工标注待完成 | 重新生成 fixture 和基线报告 |
| 04 | 高 | 同内容重复发布复用了旧 revision | 每次发布创建新的不可变 revision，版本号持续递增 | 同 manifest 连续发布测试 |
| 05 | 高 | 归档值会阻塞后续发布 | 发布时排除 `rejected` 值，活动值仍需完成审核 | 含归档旧值的发布测试 |
| 06 | 高 | 证据 hash、摘录可由客户端伪造 | HTTP 层和领域服务都从所属 chunk 派生 hash、定位和摘录 | 伪造 payload 覆盖测试 |
| 07 | 高 | 未关联或已失效证据仍可发布，并把 serving 状态写成 ready | 发布前校验 chunk 归属、source hash、content hash、excerpt hash 和摘录内容 | stale evidence 拒绝测试 |
| 08 | 高 | chunk 重建后的重连把知识库正文 hash 当成 chunk source hash | 重连改用 chunk content hash 和 section path，写回真实 chunk source hash | 重连回归测试 |
| 09 | 高 | 延迟到达的旧 reconcile job 可覆盖新状态 | reconcile 前核对当前 `chunk_source_hash`，旧任务直接退出 | 全量服务测试覆盖 |
| 10 | 高 | mutable working copy 的草稿证据会污染 active revision 健康度 | serving 状态改由 active revision 的 source 和 evidence 指纹决定，草稿未解决数只进入健康诊断 | 重连与状态测试 |
| 11 | 高 | scope JSON 键顺序和编码选项会产生不同 hash | 新增递归 canonicalization，列表保序，对象键排序 | 反序 scope 回归测试 |
| 12 | 高 | value 更新、合并和生成候选可绕过时间区间冲突校验 | 创建、更新、合并、候选落库和发布统一调用 value policy | 区间更新冲突测试 |
| 13 | 高 | 数值 canonical value 可写成浮点数，产生精度漂移 | integer、decimal、number 强制使用十进制字符串 | 数值类型回归测试 |
| 14 | 高 | 已审核内容修改后仍保持 reviewed | 事实或值的内容字段变化后自动回到 draft | review reset 测试 |
| 15 | 高 | 合并可跨 value type，目标事实也无需复审 | 合并前校验类型和区间，目标事实回到 draft | 领域约束测试 |
| 16 | 中 | revision 的不可变性依赖调用约定 | 模型层禁止 update 和 delete，数据库继续保留版本唯一约束 | 全量模型测试 |
| 17 | 高 | restore 只比较文档 source，旧 chunk 指纹也可能失效 | restore 同时核对 manifest evidence，无法确认时标记 stale | 发布服务测试 |
| 18 | 高 | cancel 依赖 finalizer，队列停摆时 active key 无法释放 | 取消事务内直接进入 cancelled 终态并释放 active key，随后尽力取消 batch | 立即取消和再次启动测试 |
| 19 | 高 | dispatch 或 finalizer 失败后 run 会长期占用 active key | dispatch 异常和 finalizer `failed()` 都进入失败终态，释放锁并记录稳定错误码 | 队列状态回归覆盖 |
| 20 | 高 | 同一批次重投会重复调用模型 | Job 增加稳定 unique key，调用模型前先检查 completed marker | mock 零调用回归测试 |
| 21 | 高 | 生成期间 working copy 被人工修改，旧候选仍会落库 | batch 和 finalize 都校验 `base_working_version`，漂移后 run 标记 obsolete | working version drift 测试 |
| 22 | 高 | initial、supplement、refresh_stale 只有标签，没有执行差异 | initial 要求空库，supplement 按目标总数补缺，refresh_stale 只保留已有 stable key 的更新建议 | mode 领域测试与全量回归 |
| 23 | 高 | 候选冲突按数组下标处理，存在下标漂移和并发重复消费 | 候选使用内容寻址 key，run 锁、library 锁、候选落库和结果更新处于同一事务 | 冲突处理服务测试 |
| 24 | 高 | AI schema 丢失时间、范围、统计口径和容差 | structured schema 和物化层补齐 temporal、scope、statistic definition、comparison tolerance，并增加长度、枚举、日期和数值校验 | AI generator 测试 |
| 25 | 中 | 生成任务 payload 携带 chunk 正文，放大队列存储和 Web 请求内存 | 队列只保存 chunk ID 与内容指纹，worker 在 source 校验后读取正文 | 队列与生成测试 |
| 26 | 中 | 生成 limiter 未注册，敏感写接口也没有单独限流 | 注册模型维度 limiter，生成、取消和冲突处理使用 `admin-sensitive` 限流 | 路由和全量测试 |
| 27 | 中 | 管理端只有创建和发布，人工审核流程无法走完 | 补齐 AI 生成、任务取消、冲突处理、事实和值审核、归档、证据绑定、合并、拆分、revision 恢复入口；详情查询改用 evidence count，避免加载完整摘录 | full-page smoke、Blade cache、前端构建 |

## 额外修复

- 诊断裁剪增加 `--dry-run`，逐 run 加锁并重新计算 `result_hash`。
- 发布 manifest 的 evidence 顺序沿用关系稳定排序，重复发布仍能得到相同内容 hash。
- 生成请求增加 UUID request key，重复提交返回同一个 run，参数变化时返回冲突。
- AI readiness profile 在模型行锁内合并更新，并校验配置指纹，避免并发覆盖。
- 生成批次在调用模型前检查 source 和 working version，减少已过期任务的 provider 消耗。
- 可预期的 active run、chunk 未就绪和模型不可用分别映射到 409 或 422。
- 新增向前迁移，移除 revision manifest hash 的唯一约束，保留普通索引，已执行过旧迁移的环境可正常升级。

## 上线门禁

### 独立人工标注集

当前 240 条数据是确定性合成协议样本，适合检查统计脚本、报告结构和回归稳定性。prediction 仍随 fixture 保存，不能证明真实模型效果。生产门禁继续保持 `production_gate_ready=false`。

解除条件：

- 盲测集由两名独立标注者完成，保留分歧和裁决记录。
- 预测结果由真实质检链路生成，数据文件不预置预测标签。
- 分别报告原子事实路径和段落 fallback 路径的准确率、误拦截率、召回率、时延和 token。
- 五次重复评测达到既定稳定性门槛。

### 生产质检接入

Phase 0 至 Phase 2 已建立 comparator 契约、事实库和生成治理。文章质检运行时切换、分流灰度、shadow 对照和回滚属于后续接入阶段。当前实现维持离线或 shadow 使用，避免提前改变线上判定结果。

## 验证记录

| 项目 | 结果 |
|---|---|
| PHP 全量测试 | 2216 passed，22388 assertions |
| JavaScript 测试 | 155 passed |
| Comparator contract | 250 cases executed successfully |
| Blade 编译 | `view:cache` 成功 |
| 前端生产构建 | Vite build 成功，99 modules transformed |
| PHP 格式化 | Pint dirty pass |
| 补丁完整性 | `git diff --check` 通过 |
| 管理端页面测试 | full-page smoke 通过，包含 945 assertions 的相关测试批次 |

## 当前判断

本轮发现的代码级阻断项已经修复。Phase 0 的真实效果门禁仍需独立标注数据和真实模型运行结果，这部分无法用合成 fixture 代替。Phase 1 和 Phase 2 已具备受控试用条件，建议先在测试知识库走一轮“生成、审核、发布、知识更新、证据重连、再次发布”的人工验收，再开始下一阶段的线上 shadow 接入。

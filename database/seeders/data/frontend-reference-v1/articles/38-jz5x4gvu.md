GEOFlow 任务不执行时，优先检查 Queue Worker、Scheduler 与 Redis。三者分别负责消费作业、按时投递和保存队列状态，任一环节异常都会影响任务执行。

## 任务不执行的现象与直连原因
在 GEOFlow 中，后台任务（如 AI 内容生成、RAG 索引、定时抓取）分别依赖两个常驻进程：
- **Queue Worker**：处理队列中的异步作业，命令为 `php artisan queue:work redis --queue=geoflow,default`。
- **Scheduler**：按计划投递周期任务，常驻命令为 `php artisan schedule:work`。
常见现象与原因对照如下：
| 现象 | 直接原因 |
| --- | --- |
| 任务长期停在排队 | Worker 未运行、队列名不匹配或 Redis 断开 |
| 到点没有创建任务 | Scheduler 未运行或时区配置错误 |
| 任务立即失败 | 模型、知识库或外部接口返回错误 |

## 排查 Queue Worker 与 Scheduler 是否运行
1. **使用健康检查脚本**
GEOFlow 提供 `bash deploy-scripts/geoflow-healthcheck.sh`，输出会直接显示 `queue` 和 `scheduler` 是否为运行状态。若脚本返回组件缺失，即可锁定问题。
2. **手动检查进程**：确认 `queue:work` 与 `schedule:work` 都有常驻进程，并核对 Worker 正在监听 `geoflow,default`。
3. **检查 Redis 连通性**：确认应用与 Worker 使用同一套 Redis 连接和队列前缀，日志中没有连接拒绝或超时。

## 启动与修复步骤
确认组件未运行后，按以下顺序恢复：
1. 确保 Redis 服务正常，并在 `.env` 中设置 `QUEUE_CONNECTION=redis`。
2. 启动 Queue Worker：
```bash
php artisan queue:work redis --queue=geoflow,default --sleep=1 --tries=1 --timeout=300
```
3. 启动 Scheduler：
```bash
php artisan schedule:work
```
4. 生产环境使用 Docker Compose 或进程守护器管理两项常驻服务，修改代码或配置后依次重启并查看日志。

## 验证与持续检查清单
修复后需要验证任务是否恢复正常，可参照如下清单逐项确认：
- [ ] `php artisan queue:work` 进程存在且状态正常
- [ ] `php artisan schedule:work` 进程存在且状态正常
- [ ] 健康检查脚本显示 queue 和 scheduler 均为 active
- [ ] 创建一个测试任务，观察是否能在预期时间内完成

## 常见问题
**Q：Worker 启动后旧任务仍不执行？**
A：先用 `php artisan queue:failed` 查看失败记录与原因，再按具体 ID 选择性重试，避免重复执行已成功任务。
**Q：用宝塔等面板部署后任务不执行？**
A：确认守护进程长期运行两条命令，并核对工作目录、PHP 版本、环境变量与 Redis 连接。

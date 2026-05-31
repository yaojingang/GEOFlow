# GEO 生产闭环发布检查清单

## 发布前确认

- 已确认目标服务器、域名、数据库、队列驱动和回滚负责人。
- 已完成数据库备份，并确认备份文件可恢复。
- `.env` 已设置 `APP_URL`、`ADMIN_BASE_PATH`、数据库、Redis、`QUEUE_CONNECTION`。
- 生产环境如需异步执行批量采集、批量评分、发布后复测，设置 `GEOAMPLIFY_GEO_ASYNC_JOBS=true` 并启动 queue worker。
- 后台至少有一个可用管理员账号。
- 后台 AI 模型配置中至少有一个真实模型或保留 Mock 模型用于低风险验收。

## 发布命令

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

## 发布后验证

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
php artisan route:list --name=geo --except-vendor
```

必须确认：

- GEO 工作台能打开。
- 引用来源库能打开，批量采集、批量评分、生成简报按钮可见。
- 参考内容简报能生成文章草稿。
- 草稿能转为正式文章并保留 `metadata.source = geo_reference_content`。
- 发布前 GEO 检查能生成分数和问题标签。
- 发布后复测能生成 `geo_publish_retests` 记录。

## 回滚方案

```bash
php artisan down
restore database backup
git checkout previous release tag
php artisan migrate:rollback --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan up
```

回滚后检查后台登录、文章列表、GEO 工作台和前台文章页。

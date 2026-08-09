# 前台参考内容与默认主题

GEOFlow v2.3.0 将官网参考内容作为可版本化、可测试的安装资产交付。全新部署在初始化完成后可直接预览完整官网，已部署站点的升级会保留现有数据。

## 交付内容

| 资产 | 数量 | 位置 |
|---|---:|---|
| 参考文章 | 50 篇 Markdown | `database/seeders/data/frontend-reference-v1/articles/` |
| 功能指南 | 35 篇 | 分类 slug `geoflow-getting-started` |
| 部署运营 | 15 篇 | 分类 slug `geoflow-deployment-operations` |
| 文章与分类元数据 | 1 份 JSON | `database/seeders/data/frontend-reference-v1/manifest.json` |
| 默认官网主题 | 1 套 | `geoflow-template-21-enterprise-signature` |
| 独立内容页 | 3 类 | `/about`、`/archive`、`/archive/{year}/{month}` |

Manifest 记录包版本、发布版本、默认主题、作者、分类和每篇文章的 slug、标题、摘要、关键词、推荐状态、发布顺序及 Markdown 路径。当前内容包版本为 `frontend-reference-v1`。

## 首次安装

```bash
php artisan geoflow:install
```

命令首先检查安装标记和业务表。全新空库会完成以下操作：

1. 创建默认管理员。
2. 导入 1 位参考作者、2 个分类和 50 篇文章。
3. 将 `geoflow-template-21-enterprise-signature` 设为激活主题。
4. 写入 `geoflow.installation` 标记，包含参考包版本与默认主题。

需要空白内容库时，使用极简安装：

```bash
php artisan geoflow:install --without-demo
```

该选项只创建管理员和安装标记，不导入参考文章，也不修改迁移提供的初始主题设置。

## 已部署站点的升级保护

常规升级流程不执行参考内容 Seeder。`geoflow:install` 会在以下任一条件成立时跳过参考内容：

- 已存在 `geoflow.installation` 安装标记。
- 站点已存在管理员、网站设置、作者、分类、文章、模型、知识库、任务或分发渠道等业务数据。
- 在已有站点上使用 `--force`，且未显式开启 `GEOFLOW_SEED_FRONTEND_DEMO`。

跳过时会保留：

- `active_theme` 与其他站点设置。
- 已有作者、分类、文章和软删除文章。
- 后台管理员账号、邮箱和密码。

对于没有安装标记的早期站点，命令检测到业务数据后会补写 `backfilled_existing_database` 标记，不执行 Seeder。

## 手动导入与内容维护

管理员需要为演示库手动导入参考包时，可以显式设置：

```env
GEOFLOW_SEED_FRONTEND_DEMO=true
GEOFLOW_SEED_FRONTEND_DEMO_OVERWRITE=false
```

随后执行：

```bash
php artisan db:seed --force
```

`FrontendReferenceSeeder` 以作者邮箱、分类 slug 和文章 slug 作为幂等键。默认只创建缺失行，保留同键的现有数据。`GEOFLOW_SEED_FRONTEND_DEMO_OVERWRITE=true` 仅适用于可重置的演示库。

需要从已审核的内容库重新生成参考包时，使用 `scripts/export-frontend-reference.php`。该工具从标准输入读取 JSON，在目标目录生成 Markdown 和 Manifest，数据库凭据不会进入输出包。

## 自动化门禁

`tests/Feature/FrontendReferenceContentTest.php` 检查以下项目：

- 文章数量、分类数量、唯一标题和唯一 slug。
- 每篇 Markdown 文件存在且内容非空。
- 发布版本与默认主题元数据正确。
- 旧版本号、本地地址和常见明文凭据特征不存在。
- Seeder 可重复执行，默认保留用户已编辑的同键行。

`tests/Feature/GeoFlowInstallCommandTest.php` 同时覆盖全新安装、极简安装、已有标记、早期业务库回填和 `--force` 保护边界。

# 正负零消费者官网

本模块是 GEOFlow 内的独立公众站模式。它使用同一套 Laravel 后台和线索管理，但不会把 GEOFlow 示例文章、归档页或通用表单暴露到正负零前台。

## 本地启用

```dotenv
ZEROPOINT_SITE_ENABLED=true
```

```bash
php artisan migrate
php artisan db:seed --class=ZeroPointPublicSiteSeeder
php artisan optimize:clear
```

本地入口：

- 官网：`http://127.0.0.1:18080/`
- 轻度预约：`http://127.0.0.1:18080/booking`
- 官网内容后台：`http://127.0.0.1:18080/geo_admin/public-pages`
- 事实与证据后台：`http://127.0.0.1:18080/geo_admin/public-facts`
- 预约线索后台：`http://127.0.0.1:18080/geo_admin/leads`

## 发布模型

公开内容按以下顺序流转：

1. 在 `public_facts` 建立可核验事实，正式机构事实至少达到 E3、公开、有效且已批准。
2. 在 `public_pages` 编辑 Markdown 草稿并绑定事实。
3. 对同一个 `content_hash` 完成 facts、medical、compliance、brand 四门审核。
4. 发布后创建不可变 `publication_snapshots`，公众页面只读取当前活动快照。
5. 草稿或事实变化会使旧审核失效；事实变更会撤下受影响活动快照。
6. 回滚不会覆盖历史记录，而是从历史快照创建新的活动发布记录。

机构事实到期后，即使没有人工操作，公众读取也会自动关闭对应非占位页面。占位页始终带 `noindex,nofollow`，并从 `sitemap.xml` 和 `llms.txt` 排除。

## 轻度预约边界

预约表单只收集称呼、联系电话、可选微信、一般到店意向、期望日期与时段，以及联系和隐私确认。它不收集病史、诊断、证件或影像资料，不判断项目适用性，也不自动确认档期。

提交成功后生成 `ZP-` 开头的申请编号。工作人员在原有线索后台人工联系，并将状态更新为已联系、已转化或已关闭。

## 正式上线门禁

- 用法定主体原件和官方查询入口替换主体资质占位内容。
- 确认地址、电话、营业时段、人员授权、项目与价格边界。
- 为四门审核指定真实责任人；不得沿用本地种子数据的原型审核记录。
- 用正式域名设置 `APP_URL`，复核 canonical、robots、sitemap、llms.txt 和结构化数据。
- 完成真实预约的人工联系闭环和隐私告知验收。
- 所有占位页解除 `is_placeholder` 后重新完成四门审核再发布。

基础版不包含实时号源、自动排班、支付、CRM 双向同步或医疗问诊功能。

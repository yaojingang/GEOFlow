# GEOFlow Admin UI V3 试点原型

这是一次全量后台改版前的独立、只读试点，用三个真实页面类型验证公共左右分栏、内容密度和复杂页面组织方式。

## 页面入口

- `index.html`：三个试点页面目录
- `pages/dashboard.html`：数据中心与运营首页
- `pages/tasks.html`：任务管理密集列表
- `pages/site-settings.html`：网站设置复杂表单
- `FINAL-PLAN.md`：评审完善后的全量实施方案

## 访问

在项目根目录运行现有静态预览服务后访问：

```text
http://127.0.0.1:4173/previews/geoflow-admin-ui-v3-pilot/index.html
```

试点只使用演示数据，不调用接口，不修改数据库，也不替换现有 Blade 页面。


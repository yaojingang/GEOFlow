# GEOFlow 专题交互原型

该原型用于浏览专题管理、编辑、批量生成、任务和前台阅读流程；正式实现见 [专题功能说明](../../implementations/topic-channel-implementation.md)。

## 本地预览

在本目录启动静态服务器后打开对应本地地址：

```bash
python3 -m http.server 8874
```

首页提供全部页面入口；文案和交互约束见 [设计说明](DESIGN.md)。原型使用演示内容，不接入正式后台数据库。

GEOFlow 通过内置的 WordPress REST 渠道连接目标 WordPress 站点，利用 Application Password 鉴权，自动分发文章、上传媒体到图库，并同步分类与标签。该渠道专为已有 WordPress 站点的运营者设计，不干扰现有流程。

## 什么是 GEOFlow 的 WordPress 分发渠道？
分发管理模块中的 WordPress REST 渠道，是一种远程内容同步能力。它使用 WordPress 官方 REST API 与应用密码进行通信，将 GEOFlow 内的文章、元数据和附件推送至外部站点。这种模式下，GEOFlow 保留管理副本，前台不公开内容，所有发布动作在目标站点完成。

## 连接 WordPress 需要满足哪些条件？
接入前需确认以下事项：
1. 目标 WordPress 站点已启用 REST API（默认开放）。
2. 为某一位用户生成并记录 Application Password，权限应支持编辑发布。
3. GEOFlow 所在服务器能正常访问目标站点的域名或 IP。

## 如何创建和配置 WordPress 渠道？
在 GEOFlow 后台导航至分发管理，创建新渠道时选择 WordPress REST 类型。基本配置过程如下：
1. 填写目标站点的完整 URL，例如 `https://your-site.com`。
2. 输入已生成的 Application Password，系统会加密存储，不会明文回显。
3. 根据需要启用文章自动同步、媒体上传等选项。

## 文章同步支持哪些操作？
WordPress 渠道复用了统一分发队列架构，支持三类基本操作：
- **发布**：将 GEOFlow 草稿或已审核文章创建为 WordPress 文章。
- **更新**：对已同步文章进行内容或元数据修改。
- **删除**：移除目标站点上的对应文章，缓解垃圾囤积。
此外，系统会写入远端元数据，方便 GEOFlow 追踪同步状态，并提供远端编辑入口。

## 媒体、分类与标签如何同步？
同步文章时，附件图片会自动上传到 WordPress 媒体库，封面图（特色图）也会被关联。分类和标签数据同样会随文章一同同步，避免手动整理。根据路线图，分类、标签及特色图的管理将在近期增强，进一步减少人工干预。

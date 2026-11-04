# 出站代理与 WAF 目标访问

GEOFlow 的统一出站安全网关默认强制直连：每次请求都会先解析目标地址、拒绝私网与保留地址，并把连接固定到已校验的 IP，同时禁止重定向、压缩响应和超限响应体。

当目标站点使用高防 WAF、需要从固定出口 IP 访问时，可以为明确的主机名单启用出站代理。代理是显式选配能力，默认关闭。

## 配置

在 `.env` 中同时配置代理地址和主机名单，两个变量都存在才会生效：

```dotenv
# 代理地址，支持 http、https、socks5、socks5h
GEOFLOW_OUTBOUND_PROXY=http://user:pass@resi.example.com:8231
# 主机名单，逗号分隔
GEOFLOW_OUTBOUND_PROXY_HOSTS=jshh.com,*.jshh.com
```

主机名单支持三种写法：

- 精确匹配：`jshh.com`，不包含其子域名；
- 后缀匹配：`*.jshh.com`，仅匹配子域名，不包含根域名；
- 通配：`*` 或 `.`，匹配所有公网主机，请谨慎使用。

只配置其中一个变量时不会启用代理，所有出站保持直连。代理地址仅接受 `http`、`https`、`socks5`、`socks5h`，其他协议会在请求阶段被出站安全网关拒绝。

修改后需要刷新配置缓存并重启 Web 与队列进程：

```bash
php artisan config:clear
php artisan queue:restart
```

## 生效范围与安全边界

- 只有命中名单的主机才会走代理，AI 模型、搜索、更新器等其他出站保持原有直连路径。
- 未获准的私网与保留地址目标仍被出站网关拒绝；`GEOFLOW_OUTBOUND_PRIVATE_TARGETS` 中的精确例外始终直连。
- 调用方在 HTTP 请求里自带的 proxy 选项会被忽略，代理只能由上面的主机名单决定。
- 代理连接使用 GEOFlow 已校验的目标 IP，保留原始 Host、TLS SNI 和证书校验；HTTP、HTTPS 代理通过 CONNECT 隧道传输，SOCKS 代理接收数字 IP。代理需允许目标端口的 CONNECT；连接失败时请求失败。
- 代理传输需要 libcurl 7.49.0 或更新版本，以及 `CURLOPT_CONNECT_TO` 和 `CURLOPT_HTTPPROXYTUNNEL` 支持；缺少所需能力时网关拒绝请求。
- `socks5h` 配置会使用固定 IP 的 SOCKS5 模式发送，目标地址由网关完成解析与校验。
- `NO_PROXY` 等环境配置不会绕过显式代理策略；目标 IPv6 地址也可通过 IPv4 代理访问。
- 代理地址可能包含账号密码，只写入 `.env` 或部署平台的密钥管理，不要提交到仓库或写进工单。
- 紧急停用时清空 `GEOFLOW_OUTBOUND_PROXY_HOSTS` 并重启进程即可恢复直连。

## 验证

1. 在目标站点执行一次采集、发布或 URL 导入，确认请求成功返回。
2. 检查代理服务的访问日志，确认目标主机确实经过代理。
3. 若目标返回 403 或连接失败，先确认代理凭据、协议与主机名单拼写，再回退到直连验证问题是否来自目标站点。

# GEOFlow デプロイガイド

> Languages: [简体中文](DEPLOYMENT.md) | [English](DEPLOYMENT_en.md) | [日本語](DEPLOYMENT_ja.md) | [Español](DEPLOYMENT_es.md) | [Русский](DEPLOYMENT_ru.md)

更新日: 2026-04-15

## 1. 推奨パス

公開版は Docker Compose でのデプロイを推奨します。

理由:

- 最短で起動できる
- 現在の公開リポジトリ構成と一致する
- `web + postgres + scheduler + worker` を含む
- 古い SQLite / Caddy / PHP-FPM 手順を避けられる

最短で始めるなら次を読んでください。

- `docs/deployment/DOCKER_DEPLOYMENT.md`

## 2. 最低要件

- Docker 24+
- Docker Compose v2
- 最低 2 CPU / 2GB RAM / 20GB Disk
- 外部アクセス可能な Linux サーバー

## 3. 基本手順

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow

cp .env.example .env.prod
vi .env.prod

docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

デプロイ後:

- Front-end: `https://your-domain.com/`
- Admin: `https://your-domain.com/geo_admin/`

## 4. 必須設定

`.env.prod` では少なくとも以下を確認してください。

```env
SITE_URL=https://your-domain.com
APP_SECRET_KEY=replace-with-a-long-random-secret

DB_DRIVER=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_NAME=geo_system
DB_USER=geo_user
DB_PASSWORD=change-this-password

HOST_PORT=18080
CRON_INTERVAL=60
TZ=Asia/Shanghai
REQUIRE_STRONG_APP_SECRET=true
```

## 5. 実行サービス

- `web`: フロントと管理画面
- `postgres`: ランタイム DB
- `scheduler`: タスク走査、入隊、自動公開
- `worker`: モデル実行と本文生成

## 6. セキュリティ

- 初回デプロイ後に管理者パスワードを変更
- 本番では `APP_SECRET_KEY` を差し替える
- `.env.prod` をコミットしない
- PostgreSQL をデフォルトで公開しない
- 公開は HTTP/HTTPS のみ

## 7. 更新とロールバック

更新:

```bash
git pull origin main
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

ロールバック:

- 目標 commit / tag に戻す
- 同じ `docker compose ... up -d --build` を再実行する

## 8. 適用範囲

この文書は現在の公開リポジトリに対応するデプロイ方法のみを扱います。

古い SQLite、ローカルスクリプト運用、Caddy / PHP-FPM 手順は公開版の推奨ルートではありません。

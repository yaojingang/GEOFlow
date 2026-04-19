# GEOFlow

> Languages: [简体中文](README.md) | [English](README_en.md) | [日本語](README_ja.md) | [Español](README_es.md) | [Русский](README_ru.md)

> GEO / SEO 向けのコンテンツ運用に特化したオープンソースのコンテンツ生成システムです。モデル設定、素材管理、タスク実行、レビュー、公開までを一つの流れで扱えます。

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)
[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE)

Released under the Apache License 2.0.

## 画面プレビュー

<p>
  <img src="docs/images/screenshots/home.png" alt="GEOFlow ダッシュボード画面プレビュー" width="48%" />
  <img src="docs/images/screenshots/task-management.png" alt="GEOFlow タスク管理プレビュー" width="48%" />
</p>
<p>
  <img src="docs/images/screenshots/article-management.png" alt="GEOFlow 素材管理プレビュー" width="48%" />
  <img src="docs/images/screenshots/ai-config.png" alt="GEOFlow AI 設定プレビュー" width="48%" />
</p>

これら 4 画面で、ホーム、タスク実行、記事ワークフロー、モデル設定の主要導線を確認できます。その他の管理画面は `docs/` にまとめています。

## GEOFlow でできること

- AI を使った GEO / SEO 記事生成タスクの実行
- タイトル、プロンプト、画像、知識ベースの管理
- 下書き → レビュー → 公開ワークフロー
- API と CLI による自動化連携
- SEO メタデータ付きの記事ページ出力

## クイックスタート

### Docker

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow
cp .env.example .env
docker compose --profile scheduler up -d --build
```

- フロントエンド: `http://localhost:18080`
- 管理画面: `http://localhost:18080/geo_admin/`

### ローカル PHP + PostgreSQL

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow

export DB_DRIVER=pgsql
export DB_HOST=127.0.0.1
export DB_PORT=5432
export DB_NAME=geo_system
export DB_USER=geo_user
export DB_PASSWORD=geo_password

php -S localhost:8080 router.php
```

## 初期管理者アカウント

- ユーザー名: `admin`
- パスワード: `admin888`

初回ログイン後、管理者パスワードと `APP_SECRET_KEY` を変更してください。

## 実行構成

```text
管理画面
  ↓
スケジューラ / キュー
  ↓
Worker が AI 生成を実行
  ↓
下書き / レビュー / 公開
  ↓
フロントエンド表示
```

## 主要ディレクトリ

- `admin/` 管理画面
- `api/v1/` 外部 API 入口
- `bin/` CLI、スケジューラ、Worker
- `docker/` コンテナ設定
- `docs/` 公開ドキュメント
- `includes/` コアサービスと業務ロジック

## 連携 Skill

- Skill リポジトリ: [yaojingang/yao-geo-skills](https://github.com/yaojingang/yao-geo-skills)
- Skill パス: `skills/geoflow-cli-ops`

## ドキュメント

- [Docs index](docs/README_ja.md)
- [FAQ](docs/FAQ_ja.md)
- [Deployment](docs/deployment/DEPLOYMENT_ja.md)
- [CLI guide](docs/project/GEOFLOW_CLI_ja.md)

## 公開リポジトリの範囲

- ソースコード、設定テンプレート、公開ドキュメントを含みます
- 本番データベース、アップロード済みファイル、実 API キーは含みません
- セルフホスト運用と二次開発を想定しています

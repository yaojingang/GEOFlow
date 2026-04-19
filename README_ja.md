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
  <img src="docs/images/screenshots/dashboard-en.png" alt="GEOFlow ダッシュボード画面プレビュー" width="48%" />
  <img src="docs/images/screenshots/tasks-en.png" alt="GEOFlow タスク管理プレビュー" width="48%" />
</p>
<p>
  <img src="docs/images/screenshots/materials-en.png" alt="GEOFlow 素材管理プレビュー" width="48%" />
  <img src="docs/images/screenshots/ai-config-en.png" alt="GEOFlow AI 設定プレビュー" width="48%" />
</p>

これら 4 画面で、ホーム、タスク実行、記事ワークフロー、モデル設定の主要導線を確認できます。その他の管理画面は `docs/` にまとめています。

## GEOFlow でできること

- AI を使った GEO / SEO 記事生成タスクの実行
- タイトル、プロンプト、画像、知識ベースの管理
- 下書き → レビュー → 公開ワークフロー
- API と CLI による自動化連携
- SEO メタデータ付きの記事ページ出力

## 🎯 想定シーンと得られる価値

GEOFlow は次のような実務シーンに向いています。

- **独立した GEO 公式サイト**  
  製品説明、FAQ、事例、ブランド知識を継続的に整理・公開するサイトとして運用できます。目的は AI 検索での可視性や信頼性を高めることであり、低品質ページを量産することではありません。
- **既存公式サイト内の GEO サブチャンネル**  
  既存サイトの中に、ニュース、ナレッジ、解説などの専用チャンネルを追加できます。目的は情報を構造化し、検索や引用に強い状態にすることです。
- **独立した GEO 信源サイト**  
  特定の業界やテーマに特化した記事、ガイド、ランキング、解説を継続的に蓄積できます。目的は信頼できる外部コンテンツ資産を作ることであり、情報汚染ではありません。
- **社内向け GEO コンテンツ管理システム**  
  モデル、素材、知識ベース、プロンプト、レビュー、公開フローをまとめて扱う内部バックエンドとして利用できます。目的は運用効率の向上です。
- **マルチサイト / マルチチャンネル運用**  
  複数のテーマ、サイト、チャンネルを同じ運用設計で管理できます。目的は標準化と保守性の向上です。

このシステムの価値は、**真实で質の高い知識ベース**を前提にしてはじめて成立します。  
GEOFlow は、インターネットをノイズで汚すための仕組みではなく、信頼できる情報をより効率よく管理・配信するための基盤です。

## 🧭 シーン別の導入・利用方法

- **独立 GEO サイトとして導入**  
  フロントエンドと管理画面をまとめて導入し、公式情報や解説コンテンツの拠点として運用します。
- **公式サイトの GEO サブチャンネルとして導入**  
  既存サイトを大きく作り直さず、サブドメインやディレクトリ配下で専用チャンネルとして展開します。
- **GEO 信源サイトとして導入**  
  まず知識ベースの整備を優先し、その上でタスク機能を使って安定的に更新します。
- **社内コンテンツ運用基盤として導入**  
  前台よりも后台、素材管理、API / CLI / Skill 連携を重視して、内部の制作・配信基盤として使います。
- **マルチサイト運用基盤として導入**  
  複数テーマや複数ブランド向けに、同じワークフローを横展開します。

おすすめの順序は次の通りです。

1. 先に実際の業務目的と読者を定義する  
2. 先に知識ベースを整備する  
3. 内容の正確性と継続保守性を確保する  
4. その上で自動化によって効率を高める  

知識ベースが弱いまま自動化を強めると、ノイズだけが増えます。GEOFlow では **知識ベースの品質を最優先**にすべきです。

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

# GEOFlow

> Languages: [简体中文](../../README.md) | [English](README_en.md) | [日本語](README_ja.md) | [Español](README_es.md) | [Русский](README_ru.md)

> GEOFlow は GEO（Generative Engine Optimization）に特化して設計されたオープンソースのインテリジェント・コンテンツエンジニアリングシステムです。GEO シナリオを中心に体系的に設計された、世界でも最も早いデータ・コンテンツ・配信インフラの一つであり、データ資産、ナレッジベース、素材管理、AI 生成、レビュー、公開、フロント表示、将来的なマルチチャネル配信までを継続的に進化する一つのパイプラインとして結びます。

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20)](https://laravel.com/)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)
[![License](https://img.shields.io/badge/License-Apache--2.0-blue.svg)](../../LICENSE)
[![GitHub stars](https://img.shields.io/github/stars/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/network/members)
[![GitHub issues](https://img.shields.io/github/issues/yaojingang/GEOFlow)](https://github.com/yaojingang/GEOFlow/issues)

GEOFlow は [Apache License 2.0](../../LICENSE) の下で公開されています。著作権表示とライセンス表示を保持し、Apache-2.0 の特許、商標、保証免責に関する条件を遵守する限り、商用利用を含む利用、複製、変更、再配布が可能です。

---

## ✨ GEOFlow でできること

| 機能 | 説明 |
|------|------|
| 🤖 マルチモデル生成 | OpenAI 互換 API、chat / embedding、Provider URL 自動適配、スマートフェイルオーバー |
| 📦 バッチタスク | タスク作成、生成数、公開頻度、キュー、失敗記録、タスク別記事フィルタ |
| 🗂 素材の一元管理 | タイトル、キーワード、画像、作者、ナレッジ、プロンプト |
| 🧠 ナレッジ RAG | アップロード文書の分割、embedding 設定時のベクトル保存、生成時の関連文脈検索 |
| 📋 レビューと公開 | 下書き、レビュー、公開、状態・作者・タスクによる記事フィルタ |
| 🔍 検索向け表示 | SEO メタ、OG、構造化データ、見出し・表・リスト・画像の GFM Markdown 表示 |
| 🎨 フロントとテーマ | デフォルトテーマ、テーマパッケージ、プレビュー、管理画面でのテーマ切替 |
| 🌍 管理画面 i18n | 中国語、英語、日本語、スペイン語、ロシア語 |
| 🔔 バージョン通知 | GitHub `version.json` を確認し、新バージョンを管理画面で通知 |
| 🐳 すぐデプロイ | **Docker Compose**：PostgreSQL（pgvector）、Redis、アプリ、キュー、スケジューラ、Reverb |
| 🗄 PostgreSQL | デフォルト DB。安定運用と同時書き込みに適する |

---

## 🖼 画面プレビュー

<p>
  <img src="../../docs/images/screenshots/dashboard-en.png" alt="GEOFlow ダッシュボード" width="48%" />
  <img src="../../docs/images/screenshots/tasks-en.png" alt="GEOFlow タスク管理" width="48%" />
</p>
<p>
  <img src="../../docs/images/screenshots/materials-en.png" alt="GEOFlow 素材管理" width="48%" />
  <img src="../../docs/images/screenshots/ai-config-en.png" alt="GEOFlow AI 設定" width="48%" />
</p>

ホーム、タスク、記事フロー、モデル設定の主要導線をカバーします。画像パスが未配置の場合はローカルで補完してください。

---

## 🆕 現行 Laravel 版の主な更新点

現在の公開版は Laravel 12 ベースのリライト版です。

- 管理画面は GEOFlow ブランド固定、多言語切替、管理者編集・削除、初回歓迎ページ、GitHub 更新通知に対応しています。
- タスクは固定モデルとスマートフェイルオーバーを選択でき、生成と公開を別ステップとして扱います。
- 素材はナレッジ、タイトル、キーワード、画像、作者を管理対象に含みます。
- ナレッジは分割され、embedding モデル設定時にベクトル化して RAG に利用できます。
- モデル設定は OpenAI 互換 API と `/v1` 以外の Provider パスに対応します。
- フロントは GFM Markdown を使い、表・見出し・リスト・画像を表示し、古い `/uploads` 画像パスも互換処理します。

---

## 🏗 実行構成

```
管理画面
  ↓
スケジューラ / キュー（Horizon は任意）
  ↓
Worker が AI 生成
  ↓
下書き / レビュー / 公開
  ↓
フロント表示
```

---

## 🧱 システムアーキテクチャ

| 層 | 説明 |
|----|------|
| Web / Admin | **Laravel** ルートとコントローラ。**Blade** 管理画面と記事サイト |
| API | `routes/api.php` など（認証はプロジェクト設定に従う） |
| Scheduler / Queue / Reverb | **Scheduler**、**`queue:work` / Horizon**、必要に応じ **Reverb** |
| ドメイン / Jobs | `app/Services`、`app/Jobs`、`app/Http/Controllers` などで業務ルールと GEO パイプライン |
| 永続化 | **PostgreSQL**（**pgvector** 推奨）+ **Redis**（キュー／キャッシュなど） |

主な流れ：管理でモデル・プロンプトを設定 → ナレッジ、タイトル、キーワード、画像、作者を準備 → タスク作成とキュー投入 → Worker が生成 → 下書き〜公開 → フロントで SEO 付き記事を表示。

---

## ⚡ 管理画面の最短導線

1. **API を設定**：少なくとも 1 つの chat モデルを追加します。RAG を使う場合は embedding モデルも追加します。
2. **素材を設定**：ナレッジ、タイトル、キーワード、画像、作者を準備します。まずは実在し検証できる資料を使ってください。
3. **タスクを作成**：素材、モデル、生成数、公開頻度を選び、最初は下書きまたはレビューから検証します。

---

## 🎯 想定シーンと得られる価値

GEOFlow は次のような実務シーンに向いています。

- **独立した GEO 公式サイト**  
  製品説明、FAQ、事例、ブランド知識を継続的に整理・公開するサイトとして運用できます。目的は AI 検索での可視性や信頼性を高めることであり、低品質ページを量産することではありません。
- **既存公式サイト内の GEO サブチャンネル**  
  既存サイトの中に、ニュース、ナレッジ、解説などの専用チャンネルを追加できます。目的は情報を構造化し、検索や引用に強い状態にすることです。
- **独立した GEO 信源サイト**  
  特定の業界やテーマに特化した記事、ガイド、ランキング、解説を継続的に蓄積できます。目的は信頼できる外部コンテンツ資産を作ることであり、情報汚染ではありません。
- **社内向け GEO コンテンツ管理システム**  
  モデル、素材、ナレッジ、プロンプト、レビュー、公開フローをまとめて扱う内部バックエンドとして利用できます。目的は運用効率の向上です。
- **マルチサイト / マルチチャンネル運用**  
  複数のテーマ、サイト、チャンネルを同じ運用設計で管理できます。目的は標準化と保守性の向上です。
- **自動化された信源管理と配信**  
  ナレッジ、特集、更新、配信をエンジニアリングして運用できます。目的は価値ある情報をユーザーと AI にとって理解・引用・検索しやすくすることです。

このシステムの価値は、**真実で質の高く維持されたナレッジベース**を前提にしてはじめて成立します。  
インターネットをノイズで汚すための仕組みではなく、信頼できる情報をより効率よく管理・配信するための基盤です。

---

## 🧭 シーン別の導入・利用方法

- **独立 GEO サイトとして導入**  
  フロントエンドと管理画面をまとめて導入し、公式情報や解説コンテンツの拠点として運用します。
- **公式サイトの GEO サブチャンネルとして導入**  
  既存サイトを大きく作り直さず、サブドメインやディレクトリ配下で専用チャンネルとして展開します。
- **GEO 信源サイトとして導入**  
  まずナレッジベースの整備を優先し、その上でタスク機能を使って安定的に更新します。
- **社内コンテンツ運用基盤として導入**  
  前台よりも后台、素材管理、API 連携を重視して、内部の制作・配信基盤として使います。
- **マルチサイト運用基盤として導入**  
  複数テーマや複数ブランド向けに、同じワークフローを横展開します。
- **自動化された信源管理システムとして導入**  
  タイトル庫、画像庫、プロンプト体系、ナレッジを長期的なインフラとして扱います。

おすすめの順序は次の通りです。

1. 先に実際の業務目的と読者を定義する  
2. 先にナレッジベースを整備する  
3. 内容の正確性と継続保守性を確保する  
4. その上で自動化によって効率を高める  

ナレッジベースが弱いまま自動化を強めると、ノイズだけが増えます。GEOFlow では **ナレッジベースの品質を最優先**にすべきです。

---

## 🚀 クイックスタート

### 方法 1：Docker（開発／デモ）

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow
cp .env.example .env
vi .env

docker compose build
docker compose up -d
```

- サイト: `http://localhost:18080`（**`APP_PORT`**、既定 `18080`）  
- 管理ログイン: `http://localhost:18080/geo_admin/login`（**`ADMIN_BASE_PATH`**、既定 `geo_admin`）  

**`docker-compose.yml`** では **`init`** が DB 準備後に初回マイグレーションと `db:seed` を実行します（既定管理者は下表参照）。

### 補足：Docker（本番）

本番は **`docker-compose.prod.yml`** で **Nginx + php-fpm**（`php artisan serve` ではない構成）を推奨します。

```bash
cp .env.prod.example .env.prod
vi .env.prod

docker compose --env-file .env.prod -f docker-compose.prod.yml build
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d postgres redis
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d init
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d app web queue scheduler reverb
```

- フロント／管理は `web`（Nginx）経由、PHP は `app`（php-fpm）。
- **既定管理者:** 本番の `entrypoint.prod.sh` は自動で `db:seed` しません。マイグレーション成功後に **1 回だけ** 実行するコマンドとアカウントは **`../../docs/deployment/DEPLOYMENT.md`**（「默认管理员（首次种子）」節・本文は中国語）に記載しています。
- 手順の詳細は **`../../docs/deployment/DEPLOYMENT.md`** を参照してください。

### 方法 2：ローカル PHP

**前提:** PHP **8.2+**（`pdo_pgsql`、`redis` 等）、**PostgreSQL**、**Redis**、**Composer 2.x**。

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow
cp .env.example .env
composer install --no-interaction --prefer-dist
php artisan key:generate

php artisan migrate --force
php artisan db:seed --force
php artisan storage:link

php artisan serve --host=127.0.0.1 --port=8080
```

別ターミナル:

```bash
php artisan queue:work redis --queue=geoflow,default --sleep=1 --tries=1 --timeout=300
php artisan schedule:work
php artisan reverb:start
```

管理: `http://127.0.0.1:8080/geo_admin/login`。**本番**は Nginx + PHP-FPM、ドキュメントルートは **`public/`**。

---

## 環境チェックリスト

| 項目 | メモ |
|------|------|
| PHP | **8.2+**（Docker は 8.4 の場合あり） |
| DB | **PostgreSQL**（**pgvector** 推奨） |
| Redis | キュー用（ローカル検証のみ `QUEUE_CONNECTION=sync` 可） |

---

## デフォルト管理者（`db:seed` 後）

| 項目 | 値 |
|------|-----|
| ユーザー名 | `admin` |
| パスワード | `password`（**本番では直ちに変更**） |

### ログイン失敗時のロックと手動解除

- 管理者アカウントは、連続 **5** 回ログインに失敗すると自動的にロックされます（`status=locked`）。
- ロックされたアカウントは、管理者による手動解除までログインできません。
- 解除コマンド:

```bash
php artisan geoflow:admin-unlock <username>
```

例:

```bash
php artisan geoflow:admin-unlock admin
```

---

## Docker 補足

**開発**（`docker-compose.yml`）: `postgres` / `redis` / `init` / `app`（`${APP_PORT:-18080}:8080`）/ `queue` / `scheduler` / `reverb`（`${REVERB_EXPOSE_PORT:-18081}:8080`）。`docker/entrypoint.sh` の変数は [README_en.md](README_en.md) と同趣旨です。

**本番**（`docker-compose.prod.yml`）: `docker compose --env-file .env.prod -f docker-compose.prod.yml …` で起動（上記「補足：Docker（本番）」および **`../../docs/deployment/DEPLOYMENT.md`**）。

---

## 開発とテスト

```bash
composer test
./vendor/bin/pint
```

---

## 🌍 他言語 README

- [简体中文](../../README.md)
- [English](README_en.md)
- [Español](README_es.md)
- [Русский](README_ru.md)

---

## 📄 ライセンス

GEOFlow は [Apache License 2.0](../../LICENSE) の下で提供されます。このライセンスは、ライセンス表示、著作権表示、変更通知、特許条項、保証免責を遵守する限り、個人利用、商用利用、変更、再配布、非公開デプロイを許可します。

---

## ⭐ スター推移

[![Star History Chart](https://api.star-history.com/svg?repos=yaojingang/GEOFlow&type=Date)](https://star-history.com/#yaojingang/GEOFlow&Date)

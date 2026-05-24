# GEOFlow

> Languages: [简体中文](../../README.md) | [English](README_en.md) | [日本語](README_ja.md) | [Español](README_es.md) | [Русский](README_ru.md) | [Português (BR)](README_pt_BR.md)

> GEOFlow は GEO（Generative Engine Optimization）向けのオープンソース・コンテンツエンジニアリング／マルチサイト配信システムです。ナレッジベース、素材ライブラリ、プロンプト、AI 生成タスク、レビューと公開、データ分析、GEOFlow Agent ターゲットサイトパッケージ、WordPress REST チャネル、リモート静的ページ配信を一つの運用フローに統合し、信頼できる資料を追跡可能で公開・配信しやすい GEO コンテンツ資産へ変換します。

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)
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
| 🤖 マルチモデル生成 | OpenAI 互換 API と Gemini ネイティブ API、chat / embedding、Provider URL 自動適配、スマートフェイルオーバー、リトライ、利用統計 |
| 🧠 ナレッジ RAG | ルールベース分割、任意の LLM セマンティック計画、安定フォールバック、embedding 設定時のベクトル保存、生成時の関連文脈検索 |
| 🗂 素材とプロンプト | タイトル、キーワード、画像、作者、ナレッジ、本文プロンプト、特殊プロンプト |
| 📦 タスク自動化 | 生成数、下書きプール、レビュー設定、公開頻度、キュー、失敗リトライ、公開範囲、タスク別記事フィルタ |
| 📋 レビューと記事管理 | 下書き、レビュー、公開、ゴミ箱、作者、カテゴリ、SEO、タスク由来を一元管理 |
| 📡 マルチサイト配信 | GEOFlow Agent と WordPress REST チャネル、シークレット、ターゲットサイトパッケージ、静的モード、rewrite ルール、リモート編集/削除、キュー、ログ |
| 🧾 ターゲットサイトパッケージ | チャネルごとの PHP Agent、ホーム、記事ページ、静的アセット、sitemap、`llms.txt` / TXT マップ、Schema |
| 📊 データ分析 | システム概要、単一サイト運用、マルチサイト配信、アクセスログ、Top コンテンツ、AI クローラー識別、トレンド |
| 🔍 SEO と LLM 向け出力 | SEO メタ、OG、Schema、GFM Markdown、独立 CSS、画像同期、sitemap、TXT マップ |
| 🎨 フロントとテーマ | テーマ、プレビュー、管理画面でのテーマ切替、リモートサイトのタイトル・著作権・テーマ・カテゴリ同期 |
| 🌍 管理画面 i18n | 中国語、英語、日本語、スペイン語、ロシア語、ポルトガル語（ブラジル）。GEOFlow 2.0 モジュールも対象 |
| 🔔 バージョン通知 | GitHub `version.json` を確認し、新バージョンを管理画面で通知 |
| 🐳 すぐデプロイ | **Docker Compose**：PostgreSQL（pgvector）、Redis、アプリ、キュー、スケジューラ、Reverb、本番 Nginx/php-fpm |

---

## 🖼 画面プレビュー

<table>
  <tr>
    <td width="33%"><img src="../../docs/images/screenshots/dashboard-en.png" alt="GEOFlow admin dashboard preview" /><br /><sub>Admin Dashboard</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/analytics-en.png" alt="GEOFlow analytics preview" /><br /><sub>Analytics</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/tasks-en.png" alt="GEOFlow task management preview" /><br /><sub>Task Management</sub></td>
  </tr>
  <tr>
    <td width="33%"><img src="../../docs/images/screenshots/distribution-channel-en.png" alt="GEOFlow distribution channel preview" /><br /><sub>Distribution Channel</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/materials-en.png" alt="GEOFlow materials preview" /><br /><sub>Materials</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/ai-config-en.png" alt="GEOFlow AI model configuration preview" /><br /><sub>AI Model Configuration</sub></td>
  </tr>
  <tr>
    <td width="33%"><img src="../../docs/images/screenshots/site-settings-en.png" alt="GEOFlow site settings preview" /><br /><sub>Site Settings</sub></td>
    <td width="33%"></td>
    <td width="33%"></td>
  </tr>
</table>

管理画面トップ、データ分析、タスク、マルチサイト配信、素材、モデル設定、サイト設定の主要導線をカバーします。

---

## 🆕 新バージョンの主な更新点

GEOFlow 2.0 の主な変更点は次のとおりです。

- **管理画面を運用ナビゲーション化**：3 ステップ導線を残し、単一サイト運用、マルチサイト配信、関連 skill リソースに整理。
- **Gemini と OpenAI 互換 Provider を両方サポート**：モデル設定で OpenAI 互換ルートと Gemini ネイティブ chat / embedding を扱えます。
- **ナレッジ分割にセマンティック計画を追加**：ルールベース、自動、任意の LLM セマンティック計画を選択できます。LLM は境界だけを計画し、最終 chunk は原文から安定的に再構築されます。
- **データ分析ページを独立化**：システム概要、コンテンツ運用、タスク/素材の健全性、配信状況、アクセスログ、AI クローラートレンドを `/admin/analytics` に集約。
- **配信管理が実運用可能に**：GEOFlow Agent と WordPress REST チャネル、シークレット、接続テスト、ターゲットサイトパッケージ、静的/rewrite モード、リモート設定同期、キュー、ログ、リモート編集/削除を提供。
- **公開範囲を明確化**：本体サイト＋チャネル、チャネルのみ、本体サイトのみを選択可能。本体サイトのみではチャネル選択が無効になります。
- **ターゲットサイトを静的運用可能に**：配信時にホーム、記事ページ、sitemap、TXT マップ、`llms.txt`、画像、独立 CSS を再生成。
- **素材と RAG を強化**：ナレッジ分割、ベクトル化状態、タイトル、キーワード、画像、作者、プロンプトをタスク入力層として統合。
- **デプロイと安全性を改善**：本番 Docker は Nginx + PHP-FPM、既存管理者を seed で上書きせず、Docker/Composer ミラーを設定可能。
- **現在の管理画面キーを多言語でカバー**：2.0 新モジュールで裸の翻訳 key や英語フォールバックが出にくくなりました。

---

## 🏗 実行構成

```
管理画面
  ↓
AI 設定 / 素材 / プロンプト / タスク設定
  ↓
スケジューラ / キュー / Worker が AI 生成
  ↓
下書き / レビュー / 公開
  ↓
ローカルフロント記事と SEO ページ
  ↓
配信キュー / ターゲットサイト Agent
  ↓
リモート静的ホーム、記事ページ、sitemap、TXT マップ、llms.txt
```

---

## 🧱 システムアーキテクチャ

| 層 | 説明 |
|----|------|
| Web / Admin | **Laravel** ルートとコントローラ。記事サイト、**Blade** 管理画面、分析、配信、素材、タスク |
| API / Agent | ローカル API とターゲットサイト PHP Agent。ヘルスチェック、記事受信/更新/削除、リモート設定同期、静的ファイル生成 |
| Scheduler / Queue / Reverb | **Scheduler**、**`queue:work` / Horizon** による生成・配信処理、必要に応じ **Reverb** |
| ドメイン / Jobs | `app/Services`、`app/Jobs`、`app/Http/Controllers` などで AI 生成、RAG、公開、配信、ログ分析を処理 |
| 永続化 | **PostgreSQL**（**pgvector** 推奨）+ **Redis** + ターゲットサイト JSON/静的ファイル |

主な流れ：管理でモデル・プロンプトを設定 → ナレッジ、タイトル、キーワード、画像、作者を準備 → タスク作成とキュー投入 → Worker が生成 → 下書き〜公開 → ローカル SEO ページ出力 → 選択チャネルへ配信 → 分析で生産・配信・アクセス・AI クローラーを確認。

---

## ⚡ 管理画面の最短導線

1. **API を設定**：少なくとも 1 つの chat モデルを追加します。RAG を使う場合は embedding モデルを追加し、ナレッジ分割戦略を選びます。
2. **素材を設定**：ナレッジ、タイトル、キーワード、画像、作者を準備します。まずは実在し検証できる資料を使ってください。
3. **タスクを作成**：素材、モデル、生成数、公開頻度、公開範囲を選び、最初は下書きまたはレビューから検証します。

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
- **既定管理者:** 本番の `init` サービスはマイグレーション後に `db:seed` を実行し、既定の管理者アカウントを作成します。再実行しても既存の `admin` ユーザーは上書きされません。
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
php artisan queue:work redis --queue=geoflow,distribution,default --sleep=1 --tries=1 --timeout=300
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
| ユーザー名 | `GEOFLOW_ADMIN_USERNAME`、既定は `admin` |
| パスワード | ローカル開発では既定 `password`。本番では `GEOFLOW_ADMIN_PASSWORD` を設定してください。未設定でアカウントがまだ存在しない場合、seeder は一回限りのランダムパスワードを init / `db:seed` ログに出力します。 |

Seeder は対象ユーザー名が存在しない場合のみ作成します。再実行しても既存のユーザー名、メール、パスワードは上書きされません。

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

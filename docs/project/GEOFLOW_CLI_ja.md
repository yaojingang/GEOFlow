# GEOFlow CLI ガイド

> Languages: [简体中文](GEOFLOW_CLI.md) | [English](GEOFLOW_CLI_en.md) | [日本語](GEOFLOW_CLI_ja.md) | [Español](GEOFLOW_CLI_es.md) | [Русский](GEOFLOW_CLI_ru.md)

`geoflow` は第1段階 API のローカル CLI 入口です。

GEOFlow とは正式な `/api/v1` を通じてのみ通信します。DB へ直接アクセスせず、管理画面 session も再利用しません。

## 1. 実行入口

```bash
./bin/geoflow help
```

## 2. 設定優先順位

設定ソース:

1. CLI 引数
2. 環境変数
3. 設定ファイル

優先順位:

`CLI 引数 > 環境変数 > .geoflow.json > ~/.config/geoflow/config.json`

## 3. 初回ログイン

```bash
./bin/geoflow \
  login \
  --base-url http://127.0.0.1:18080 \
  --username admin
```

`--password` を省略すると安全なプロンプトが出ます。

トークンがある場合は手動初期化も可能です。

```bash
./bin/geoflow \
  config init \
  --base-url http://127.0.0.1:18080 \
  --token gf_xxx
```

現在の設定確認:

```bash
./bin/geoflow config show
```

## 4. よく使うコマンド

catalog:

```bash
./bin/geoflow catalog
```

タスク一覧:

```bash
./bin/geoflow task list --status active
```

タスク作成:

```bash
./bin/geoflow task create --json ./task.json
```

開始と入隊:

```bash
./bin/geoflow task start 12
./bin/geoflow task enqueue 12
```

job 照会:

```bash
./bin/geoflow task jobs 12 --limit 20
./bin/geoflow job get 88
```

記事作成:

```bash
./bin/geoflow article create \
  --title "CLI test article" \
  --content-file ./article.md \
  --task-id 12 \
  --author-id 5 \
  --category-id 2
```

レビューと公開:

```bash
./bin/geoflow article review 101 --status approved --note "CLI review pass"
./bin/geoflow article publish 101
```

## 5. JSON 入力例

タスク作成:

```json
{
  "name": "CLI Task Integration Test",
  "title_library_id": 1,
  "prompt_id": 6,
  "ai_model_id": 1,
  "knowledge_base_id": 1,
  "author_id": 5,
  "image_library_id": null,
  "image_count": 0,
  "need_review": true,
  "publish_interval": 3600,
  "auto_keywords": true,
  "auto_description": true,
  "draft_limit": 3,
  "is_loop": false,
  "status": "paused",
  "category_mode": "smart",
  "fixed_category_id": null
}
```

記事作成:

```json
{
  "title": "CLI Article Test",
  "content": "# CLI Article Test\n\nThis article was created through the CLI.",
  "task_id": 12,
  "author_id": 5,
  "category_id": 2,
  "status": "draft",
  "review_status": "pending",
  "keywords": "cli,test",
  "meta_description": "CLI article integration test"
}
```

## 6. 冪等キー

書き込み系コマンドは以下をサポートします。

```text
--idempotency-key <key>
```

## 7. 対応範囲

現在の対応:

- `login`
- `catalog`
- `task list/create/get/update/start/stop/enqueue/jobs`
- `job get`
- `article list/create/get/update/review/publish/trash`

未対応:

- URL import
- 非同期タイトル生成
- 画像アップロードの編成
- 高階バッチワークフロー

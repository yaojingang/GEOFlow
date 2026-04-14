# GEOFlow FAQ

> Languages: [简体中文](FAQ.md) | [English](FAQ_en.md) | [日本語](FAQ_ja.md) | [Español](FAQ_es.md) | [Русский](FAQ_ru.md)

## 1. 管理画面の URL と初期アカウントは？

- 管理画面: `/geo_admin/`
- 初期ユーザー名: `admin`
- 初期パスワード: `admin888`

初回ログイン後に管理者パスワードと `APP_SECRET_KEY` を変更してください。

## 2. Docker は必須ですか？

必須ではありません。

- Docker Compose で `web + postgres + scheduler + worker` を起動
- または PostgreSQL を用意して `php -S` で実行

最短で試すなら Docker を推奨します。

## 3. 実行時 DB は PostgreSQL ですか？

はい。公開版のランタイムは PostgreSQL です。

## 4. なぜ画像や知識ベース、記事データが入っていないのですか？

それらは運用データまたは業務データだからです。

- 画像ライブラリ
- 知識ベース原本
- 生成済み記事
- ログやバックアップ

公開リポジトリにはソースコードと設定テンプレートのみを含めています。

## 5. AI モデルはどう接続しますか？

管理画面の `AI 配置中心 → AI 模型管理` で次を設定します。

- API URL
- モデル ID
- Bearer token

OpenAI 互換 API 形式を前提にしています。

## 6. 記事生成の流れは？

1. モデル、プロンプト、素材を設定
2. タスクを作成
3. Scheduler がジョブを入隊
4. Worker が AI 生成を実行
5. 下書き / レビュー / 公開
6. フロントに記事を表示

## 7. CLI や skill はありますか？

あります。

- CLI ガイド: [project/GEOFLOW_CLI_ja.md](project/GEOFLOW_CLI_ja.md)
- Skill リポジトリ: [yaojingang/yao-geo-skills](https://github.com/yaojingang/yao-geo-skills)
- Skill 名: `geoflow-cli-ops`

## 8. 二次開発で最初に読むべき文書は？

- [系统说明文档](系统说明文档.md)
- [AI_PROJECT_GUIDE.md](AI_PROJECT_GUIDE.md)
- [project/STRUCTURE.md](project/STRUCTURE.md)
- [project/API_V1_REFERENCE_DRAFT.md](project/API_V1_REFERENCE_DRAFT.md)

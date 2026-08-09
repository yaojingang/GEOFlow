<?php

$base = require __DIR__.'/../en/manual_publications.php';

return array_replace_recursive($base, [
    'nav' => '手動公開',
    'page_title' => '手動公開ワークベンチ',
    'page_subtitle' => '外部プラットフォームへの公開作業を割り当て、進捗と完了証跡を管理します。',
    'create_title' => '手動公開作業を作成',
    'edit_title' => '作業 #:id を編集',
    'detail_title' => '手動公開作業 #:id',
    'form_subtitle' => '最終文面、公開ペルソナ、アカウント、担当者、予定時刻を設定します。',
    'article_action' => '手動公開作業を作成',
    'empty' => '条件に一致する作業はありません。',
    'none' => 'なし', 'unassigned' => '未割り当て', 'unscheduled' => '未設定',
    'duplicate_count' => '重複候補 :count 件', 'duplicate_title' => '関連する作業',
    'disclosure_notice' => '選択したペルソナの開示文は作業に保存されます。公開前に内容を確認してください。',
    'risk_clean_help' => '有効なセンシティブワード規則には一致しませんでした。',
    'button' => [
        'create' => '作業を作成', 'edit' => '編集', 'save' => '変更を保存', 'view' => '表示', 'back' => '戻る',
        'settings' => 'ペルソナとアカウント', 'export' => 'CSV 出力', 'copy' => '内容をコピー', 'copied' => 'コピー済み',
    ],
    'stats' => ['total' => 'すべて', 'ready' => '実行待ち', 'in_progress' => '実行中', 'completed' => '完了'],
    'type' => ['post' => '投稿', 'comment' => 'コメント'],
    'status' => ['draft' => '下書き', 'ready' => '実行待ち', 'in_progress' => '実行中', 'completed' => '完了', 'failed' => '失敗', 'skipped' => 'スキップ', 'cancelled' => 'キャンセル'],
    'action' => ['ready' => '実行待ちにする', 'in_progress' => '作業開始', 'completed' => '完了にする', 'failed' => '失敗にする', 'skipped' => 'スキップ', 'cancelled' => 'キャンセル'],
    'risk' => ['clean' => '問題なし', 'warning' => '注意', 'blocked' => '高リスク'],
    'filter' => [
        'all' => 'すべて', 'status' => '状態', 'type' => '種類', 'platform' => 'プラットフォーム', 'assignee' => '担当者',
        'scheduled_from' => '予定開始', 'scheduled_to' => '予定終了', 'search' => '検索', 'search_placeholder' => '内容、URL、記事、アカウント', 'apply' => '適用',
    ],
    'column' => ['work_order' => '作業', 'source' => '出典 / 文脈', 'platform' => 'プラットフォーム / アカウント', 'assignee' => '担当者', 'schedule' => '予定', 'status' => '状態', 'action' => '操作'],
    'section' => ['content' => '公開内容', 'target' => '対象と文脈', 'assignment' => 'ペルソナと割り当て', 'publish_content' => '最終公開内容', 'risk' => 'リスクと重複確認', 'complete' => '完了証跡', 'details' => '作業詳細', 'result' => '実行結果', 'history' => '状態履歴', 'actions' => '状態操作'],
    'field' => [
        'type' => '作業種類', 'article' => '元記事', 'content' => '最終内容', 'target_url' => '対象 URL', 'target_context' => '対象の文脈',
        'persona' => '公開ペルソナ', 'platform' => 'プラットフォーム', 'custom_platform' => '独自プラットフォーム名', 'account' => 'プラットフォームアカウント',
        'assignee' => '担当者', 'scheduled_at' => '予定時刻', 'initial_status' => '初期状態', 'disclosure' => '開示文スナップショット',
        'completion_url' => '公開済み URL', 'result_note' => '結果メモ', 'creator' => '作成者', 'revision' => 'リビジョン',
    ],
    'option' => ['select_article' => '承認済み記事を選択', 'select_persona' => 'ペルソナを選択', 'no_account' => '保存済みアカウントを使わない'],
    'help' => [
        'article' => '投稿には承認済みまたは自動承認済みの記事が必要です。コメントでは元記事を使用しません。',
        'content' => '最大 :count 文字。保存した文面は記事の後続変更から独立します。',
        'target_context' => 'コメントでは、回答対象のページ、スレッド、質問の要約が必要です。',
        'copy' => 'この内容スナップショットをコピーして外部プラットフォームに公開します。',
    ],
    'message' => ['created' => '作業を作成しました。', 'updated' => '作業を更新しました。', 'transitioned' => '状態を更新しました。'],
    'error' => [
        'account_mismatch' => '選択したアカウントは有効で、ペルソナとプラットフォームが一致する必要があります。',
        'article_not_approved' => '投稿には承認済みまたは自動承認済みの記事が必要です。', 'assignee_inactive' => '選択した担当者は利用できません。',
        'completed_immutable' => '完了した作業は編集できません。', 'completion_url_required' => '完了には有効な HTTP または HTTPS の公開 URL が必要です。',
        'invalid_transition' => 'この状態変更は許可されていません。', 'persona_inactive' => '選択したペルソナは利用できません。',
        'ready_requires_assignee' => '実行待ちの作業には担当者が必要です。', 'revision_conflict' => '別のセッションで更新されました。再読み込みして再試行してください。',
    ],
    'settings' => [
        'title' => '公開ペルソナとアカウント', 'subtitle' => '再利用するペルソナとアカウント参照を管理します。認証情報は保存しません。',
        'new_persona' => '新しいペルソナ', 'new_account' => '新しいプラットフォームアカウント', 'name' => 'ペルソナ名', 'tone' => '文体',
        'domain' => '専門領域', 'bio' => '紹介', 'disclosure' => '開示文', 'profile_url' => 'プロフィール URL', 'notes' => 'メモ', 'active' => '有効',
        'save_persona' => 'ペルソナを追加', 'save_account' => 'アカウントを追加', 'persona_saved' => 'ペルソナを保存しました。', 'account_saved' => 'アカウントを保存しました。', 'account_count' => ':count アカウント',
    ],
    'export' => [
        'id' => 'ID', 'type' => '種類', 'platform' => 'プラットフォーム', 'article' => '元記事', 'persona' => 'ペルソナ', 'account' => 'アカウント',
        'assignee' => '担当者', 'status' => '状態', 'scheduled_at' => '予定時刻', 'target_url' => '対象 URL', 'content' => '内容',
        'risk_status' => 'リスク状態', 'duplicates' => '重複警告', 'completion_url' => '公開済み URL', 'result_note' => '結果メモ', 'created_at' => '作成日時',
    ],
]);

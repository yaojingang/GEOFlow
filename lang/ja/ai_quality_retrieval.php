<?php

return [
    'title' => '検査方式',
    'help' => '選択したナレッジベースから利用可能な方式を判定し、新規設定では最も精度の高い方式を使用します。',
    'available' => '利用可能',
    'unavailable' => '利用不可',
    'unavailable_reasons' => '利用できない理由',
    'select_knowledge_base' => 'ナレッジベースを1つ以上選択してください',
    'selection_unavailable' => '現在利用できる検査方式はありません。疑問符を選択して理由を確認してください。',
    'details' => ':modeの説明を表示',
    'inherit' => 'タスクに従う',
    'inherit_help' => 'タスクに保存された検査方式を使用します。',
    'source_task' => 'ナレッジベースはタスク側で管理されます',
    'source_article' => 'ナレッジベースはこの記事で管理されます',
    'current_execution' => '今回の実行方式：:mode',
    'modes' => [
        'atomic_first' => ['label' => 'アトミック検査', 'badge' => '精度優先', 'description' => '公開済みの原子事実で主張を検証し、未対応の主張にはチャンクを使います。'],
        'chunk' => ['label' => 'チャンク検査', 'badge' => 'バランス', 'description' => '主張ごとに関連チャンクを取得し、精度、コスト、速度を両立します。'],
        'knowledge_broad' => ['label' => '知識ベース検査', 'badge' => '網羅性優先', 'description' => '知識ベースの各章から広く証拠を抽出し、ノイズ、トークン、時間が増えます。'],
    ],
];

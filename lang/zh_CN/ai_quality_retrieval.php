<?php

return [
    'title' => '质检方式',
    'help' => '系统会根据所选知识库判断可用方式。新配置默认使用当前可用的最高精度方式。',
    'available' => '可用',
    'unavailable' => '暂不可用',
    'unavailable_reasons' => '暂不可用原因',
    'select_knowledge_base' => '请先选择至少一个知识库',
    'selection_unavailable' => '当前没有可用的质检方式，请点击问号查看原因。',
    'details' => '查看:mode说明',
    'inherit' => '跟随任务',
    'inherit_help' => '使用任务当前保存的质检方式。',
    'source_task' => '知识库由任务配置提供',
    'source_article' => '知识库由当前文章配置提供',
    'current_execution' => '本次实际执行：:mode',
    'modes' => [
        'atomic_first' => [
            'label' => '原子质检',
            'badge' => '精准优先',
            'description' => '使用已发布的原子事实逐条核验，未覆盖主张继续使用切片。',
        ],
        'chunk' => [
            'label' => '切片质检',
            'badge' => '效率均衡',
            'description' => '按文章主张召回相关切片，兼顾准确度、成本和速度。',
        ],
        'knowledge_broad' => [
            'label' => '知识库质检',
            'badge' => '覆盖优先',
            'description' => '从知识库正文按章节做宽范围取证，噪音、Token 和耗时更高。',
        ],
    ],
];

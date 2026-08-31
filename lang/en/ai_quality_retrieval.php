<?php

return [
    'title' => 'Inspection method',
    'help' => 'Availability follows the selected knowledge bases. New configurations use the most precise available method.',
    'available' => 'Available',
    'unavailable' => 'Unavailable',
    'unavailable_reasons' => 'Why this is unavailable',
    'select_knowledge_base' => 'Select at least one knowledge base',
    'selection_unavailable' => 'No inspection method is currently available. Select a question mark to view the reasons.',
    'details' => 'View details for :mode',
    'inherit' => 'Follow task',
    'inherit_help' => 'Use the inspection method saved on the task.',
    'source_task' => 'Knowledge bases are managed by the task',
    'source_article' => 'Knowledge bases are managed by this article',
    'current_execution' => 'Last execution: :mode',
    'modes' => [
        'atomic_first' => ['label' => 'Atomic inspection', 'badge' => 'Precision first', 'description' => 'Verify claims against published atomic facts, then use chunks for uncovered claims.'],
        'chunk' => ['label' => 'Chunk inspection', 'badge' => 'Balanced', 'description' => 'Retrieve relevant chunks for each claim to balance accuracy, cost, and speed.'],
        'knowledge_broad' => ['label' => 'Knowledge inspection', 'badge' => 'Coverage first', 'description' => 'Sample broadly across knowledge-base sections with higher noise, token use, and latency.'],
    ],
];

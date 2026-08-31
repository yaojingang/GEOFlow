<?php

return [
    'title' => 'Método de inspeção',
    'help' => 'A disponibilidade acompanha as bases selecionadas. Novas configurações usam o método disponível mais preciso.',
    'available' => 'Disponível',
    'unavailable' => 'Indisponível',
    'unavailable_reasons' => 'Motivos da indisponibilidade',
    'select_knowledge_base' => 'Selecione pelo menos uma base de conhecimento',
    'selection_unavailable' => 'Nenhum método está disponível. Selecione um ponto de interrogação para ver os motivos.',
    'details' => 'Ver detalhes de :mode',
    'inherit' => 'Seguir tarefa',
    'inherit_help' => 'Usar o método de inspeção salvo na tarefa.',
    'source_task' => 'As bases de conhecimento são gerenciadas pela tarefa',
    'source_article' => 'As bases de conhecimento são gerenciadas neste artigo',
    'current_execution' => 'Última execução: :mode',
    'modes' => [
        'atomic_first' => ['label' => 'Inspeção atômica', 'badge' => 'Precisão', 'description' => 'Valida afirmações com fatos atômicos publicados e usa trechos nas lacunas.'],
        'chunk' => ['label' => 'Inspeção por trechos', 'badge' => 'Equilíbrio', 'description' => 'Recupera trechos por afirmação, equilibrando precisão, custo e velocidade.'],
        'knowledge_broad' => ['label' => 'Inspeção da base', 'badge' => 'Cobertura', 'description' => 'Amostra amplamente as seções com mais ruído, tokens e latência.'],
    ],
];

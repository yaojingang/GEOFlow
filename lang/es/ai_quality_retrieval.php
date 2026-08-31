<?php

return [
    'title' => 'Método de inspección',
    'help' => 'La disponibilidad depende de las bases seleccionadas. Las configuraciones nuevas usan el método disponible más preciso.',
    'available' => 'Disponible',
    'unavailable' => 'No disponible',
    'unavailable_reasons' => 'Motivos de la indisponibilidad',
    'select_knowledge_base' => 'Selecciona al menos una base de conocimiento',
    'selection_unavailable' => 'No hay ningún método disponible. Selecciona un signo de interrogación para ver los motivos.',
    'details' => 'Ver detalles de :mode',
    'inherit' => 'Seguir la tarea',
    'inherit_help' => 'Usar el método de inspección guardado en la tarea.',
    'source_task' => 'Las bases de conocimiento se administran en la tarea',
    'source_article' => 'Las bases de conocimiento se administran en este artículo',
    'current_execution' => 'Última ejecución: :mode',
    'modes' => [
        'atomic_first' => ['label' => 'Inspección atómica', 'badge' => 'Precisión', 'description' => 'Verifica afirmaciones con hechos atómicos publicados y usa fragmentos para las no cubiertas.'],
        'chunk' => ['label' => 'Inspección por fragmentos', 'badge' => 'Equilibrio', 'description' => 'Recupera fragmentos por afirmación y equilibra precisión, coste y velocidad.'],
        'knowledge_broad' => ['label' => 'Inspección de conocimiento', 'badge' => 'Cobertura', 'description' => 'Muestrea ampliamente las secciones con más ruido, tokens y latencia.'],
    ],
];

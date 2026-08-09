<?php

$base = require __DIR__.'/../en/manual_publications.php';

return array_replace_recursive($base, [
    'nav' => 'Publicación manual', 'page_title' => 'Panel de publicación manual',
    'page_subtitle' => 'Asigna publicaciones externas, sigue su ejecución y conserva comprobantes de finalización.',
    'create_title' => 'Crear orden de publicación manual', 'edit_title' => 'Editar orden #:id', 'detail_title' => 'Orden de publicación #:id',
    'form_subtitle' => 'Prepara el texto final, la identidad, la cuenta, el responsable y la fecha prevista.',
    'article_action' => 'Crear orden de publicación manual', 'empty' => 'No hay órdenes que coincidan con los filtros actuales.',
    'none' => 'Ninguno', 'unassigned' => 'Sin asignar', 'unscheduled' => 'Sin programar',
    'duplicate_count' => ':count posible(s) duplicado(s)', 'duplicate_title' => 'Órdenes relacionadas',
    'disclosure_notice' => 'La declaración de la identidad seleccionada se guarda en la orden. Revísala antes de publicar.',
    'risk_clean_help' => 'El contenido no coincide con ninguna regla activa de palabras sensibles.',
    'button' => ['create' => 'Crear orden', 'edit' => 'Editar', 'save' => 'Guardar cambios', 'view' => 'Ver', 'back' => 'Volver', 'settings' => 'Identidades y cuentas', 'export' => 'Exportar CSV', 'copy' => 'Copiar contenido', 'copied' => 'Copiado'],
    'stats' => ['total' => 'Total', 'ready' => 'Listas', 'in_progress' => 'En curso', 'completed' => 'Completadas'],
    'type' => ['post' => 'Publicación', 'comment' => 'Comentario'],
    'status' => ['draft' => 'Borrador', 'ready' => 'Lista', 'in_progress' => 'En curso', 'completed' => 'Completada', 'failed' => 'Fallida', 'skipped' => 'Omitida', 'cancelled' => 'Cancelada'],
    'action' => ['ready' => 'Marcar lista', 'in_progress' => 'Iniciar', 'completed' => 'Completar', 'failed' => 'Marcar fallida', 'skipped' => 'Omitir', 'cancelled' => 'Cancelar'],
    'risk' => ['clean' => 'Sin alertas', 'warning' => 'Advertencia', 'blocked' => 'Riesgo alto'],
    'filter' => ['all' => 'Todos', 'status' => 'Estado', 'type' => 'Tipo', 'platform' => 'Plataforma', 'assignee' => 'Responsable', 'scheduled_from' => 'Desde', 'scheduled_to' => 'Hasta', 'search' => 'Buscar', 'search_placeholder' => 'Contenido, URL, artículo o cuenta', 'apply' => 'Aplicar'],
    'column' => ['work_order' => 'Orden', 'source' => 'Fuente / contexto', 'platform' => 'Plataforma / cuenta', 'assignee' => 'Responsable', 'schedule' => 'Programación', 'status' => 'Estado', 'action' => 'Acción'],
    'section' => ['content' => 'Contenido', 'target' => 'Destino y contexto', 'assignment' => 'Identidad y asignación', 'publish_content' => 'Contenido final', 'risk' => 'Riesgo y duplicados', 'complete' => 'Comprobante', 'details' => 'Detalles', 'result' => 'Resultado', 'history' => 'Historial de estados', 'actions' => 'Acciones de estado'],
    'field' => ['type' => 'Tipo de orden', 'article' => 'Artículo de origen', 'content' => 'Contenido final', 'target_url' => 'URL de destino', 'target_context' => 'Contexto del destino', 'persona' => 'Identidad', 'platform' => 'Plataforma', 'custom_platform' => 'Nombre de plataforma', 'account' => 'Cuenta', 'assignee' => 'Responsable', 'scheduled_at' => 'Fecha prevista', 'initial_status' => 'Estado inicial', 'disclosure' => 'Declaración guardada', 'completion_url' => 'URL publicada', 'result_note' => 'Nota de resultado', 'creator' => 'Creada por', 'revision' => 'Revisión'],
    'option' => ['select_article' => 'Selecciona un artículo aprobado', 'select_persona' => 'Selecciona una identidad', 'no_account' => 'Sin cuenta guardada'],
    'help' => ['article' => 'Las publicaciones requieren un artículo aprobado o autoaprobado. Los comentarios no usan artículo.', 'content' => 'Máximo :count caracteres. El texto guardado no cambia si se edita el artículo.', 'target_context' => 'Los comentarios requieren un resumen de la página, conversación o pregunta.', 'copy' => 'Copia esta versión exacta y publícala en la plataforma externa.'],
    'message' => ['created' => 'Orden creada.', 'updated' => 'Orden actualizada.', 'transitioned' => 'Estado actualizado.'],
    'error' => ['account_mismatch' => 'La cuenta debe estar activa y coincidir con la identidad y la plataforma.', 'article_not_approved' => 'Se requiere un artículo aprobado o autoaprobado.', 'assignee_inactive' => 'El responsable seleccionado no está disponible.', 'completed_immutable' => 'Las órdenes completadas no se pueden editar.', 'completion_url_required' => 'Se requiere una URL HTTP o HTTPS válida para completar la orden.', 'invalid_transition' => 'Este cambio de estado no está permitido.', 'persona_inactive' => 'La identidad seleccionada no está disponible.', 'ready_requires_assignee' => 'Una orden lista debe tener responsable.', 'revision_conflict' => 'La orden cambió en otra sesión. Recarga e inténtalo de nuevo.'],
    'settings' => ['title' => 'Identidades y cuentas de publicación', 'subtitle' => 'Gestiona identidades y referencias de cuentas reutilizables. Aquí no se guardan credenciales.', 'new_persona' => 'Nueva identidad', 'new_account' => 'Nueva cuenta', 'name' => 'Nombre', 'tone' => 'Tono', 'domain' => 'Especialidad', 'bio' => 'Biografía', 'disclosure' => 'Declaración', 'profile_url' => 'URL del perfil', 'notes' => 'Notas', 'active' => 'Activa', 'save_persona' => 'Añadir identidad', 'save_account' => 'Añadir cuenta', 'persona_saved' => 'Identidad guardada.', 'account_saved' => 'Cuenta guardada.', 'account_count' => ':count cuenta(s)'],
    'export' => ['id' => 'ID', 'type' => 'Tipo', 'platform' => 'Plataforma', 'article' => 'Artículo', 'persona' => 'Identidad', 'account' => 'Cuenta', 'assignee' => 'Responsable', 'status' => 'Estado', 'scheduled_at' => 'Fecha prevista', 'target_url' => 'URL de destino', 'content' => 'Contenido', 'risk_status' => 'Riesgo', 'duplicates' => 'Duplicados', 'completion_url' => 'URL publicada', 'result_note' => 'Nota', 'created_at' => 'Creada el'],
]);

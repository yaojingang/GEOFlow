<?php

$base = require __DIR__.'/../en/manual_publications.php';

return array_replace_recursive($base, [
    'nav' => 'Publicação manual', 'page_title' => 'Área de publicação manual',
    'page_subtitle' => 'Atribua publicações externas, acompanhe a execução e mantenha os comprovantes de conclusão.',
    'create_title' => 'Criar ordem de publicação manual', 'edit_title' => 'Editar ordem #:id', 'detail_title' => 'Ordem de publicação #:id',
    'form_subtitle' => 'Prepare o texto final, a identidade, a conta, o responsável e o horário planejado.',
    'article_action' => 'Criar ordem de publicação manual', 'empty' => 'Nenhuma ordem corresponde aos filtros atuais.',
    'none' => 'Nenhum', 'unassigned' => 'Não atribuído', 'unscheduled' => 'Não agendado',
    'duplicate_count' => ':count possível(is) duplicata(s)', 'duplicate_title' => 'Ordens relacionadas',
    'disclosure_notice' => 'A declaração da identidade escolhida é salva na ordem. Revise-a antes de publicar.',
    'risk_clean_help' => 'O conteúdo não correspondeu a nenhuma regra ativa de palavras sensíveis.',
    'button' => ['create' => 'Criar ordem', 'edit' => 'Editar', 'save' => 'Salvar alterações', 'view' => 'Ver', 'back' => 'Voltar', 'settings' => 'Identidades e contas', 'export' => 'Exportar CSV', 'copy' => 'Copiar conteúdo', 'copied' => 'Copiado'],
    'stats' => ['total' => 'Total', 'ready' => 'Prontas', 'in_progress' => 'Em andamento', 'completed' => 'Concluídas'],
    'type' => ['post' => 'Publicação', 'comment' => 'Comentário'],
    'status' => ['draft' => 'Rascunho', 'ready' => 'Pronta', 'in_progress' => 'Em andamento', 'completed' => 'Concluída', 'failed' => 'Falhou', 'skipped' => 'Ignorada', 'cancelled' => 'Cancelada'],
    'action' => ['ready' => 'Marcar pronta', 'in_progress' => 'Iniciar', 'completed' => 'Concluir', 'failed' => 'Marcar falha', 'skipped' => 'Ignorar', 'cancelled' => 'Cancelar'],
    'risk' => ['clean' => 'Sem alertas', 'warning' => 'Aviso', 'blocked' => 'Alto risco'],
    'filter' => ['all' => 'Todos', 'status' => 'Status', 'type' => 'Tipo', 'platform' => 'Plataforma', 'assignee' => 'Responsável', 'scheduled_from' => 'A partir de', 'scheduled_to' => 'Até', 'search' => 'Pesquisar', 'search_placeholder' => 'Conteúdo, URL, artigo ou conta', 'apply' => 'Aplicar'],
    'column' => ['work_order' => 'Ordem', 'source' => 'Fonte / contexto', 'platform' => 'Plataforma / conta', 'assignee' => 'Responsável', 'schedule' => 'Agendamento', 'status' => 'Status', 'action' => 'Ação'],
    'section' => ['content' => 'Conteúdo', 'target' => 'Destino e contexto', 'assignment' => 'Identidade e atribuição', 'publish_content' => 'Conteúdo final', 'risk' => 'Risco e duplicatas', 'complete' => 'Comprovante', 'details' => 'Detalhes', 'result' => 'Resultado', 'history' => 'Histórico de status', 'actions' => 'Ações de status'],
    'field' => ['type' => 'Tipo da ordem', 'article' => 'Artigo de origem', 'content' => 'Conteúdo final', 'target_url' => 'URL de destino', 'target_context' => 'Contexto do destino', 'persona' => 'Identidade', 'platform' => 'Plataforma', 'custom_platform' => 'Nome da plataforma', 'account' => 'Conta', 'assignee' => 'Responsável', 'scheduled_at' => 'Horário planejado', 'initial_status' => 'Status inicial', 'disclosure' => 'Declaração salva', 'completion_url' => 'URL publicada', 'result_note' => 'Nota do resultado', 'creator' => 'Criado por', 'revision' => 'Revisão'],
    'option' => ['select_article' => 'Selecione um artigo aprovado', 'select_persona' => 'Selecione uma identidade', 'no_account' => 'Sem conta salva'],
    'help' => ['article' => 'Publicações exigem um artigo aprovado ou aprovado automaticamente. Comentários não usam artigo.', 'content' => 'Até :count caracteres. O texto salvo não muda quando o artigo é editado.', 'target_context' => 'Comentários exigem um resumo da página, conversa ou pergunta.', 'copy' => 'Copie esta versão exata e publique-a na plataforma externa.'],
    'message' => ['created' => 'Ordem criada.', 'updated' => 'Ordem atualizada.', 'transitioned' => 'Status atualizado.'],
    'error' => ['account_mismatch' => 'A conta deve estar ativa e corresponder à identidade e à plataforma.', 'article_not_approved' => 'É necessário um artigo aprovado ou aprovado automaticamente.', 'assignee_inactive' => 'O responsável selecionado não está disponível.', 'completed_immutable' => 'Ordens concluídas não podem ser editadas.', 'completion_url_required' => 'Uma URL HTTP ou HTTPS válida é necessária para concluir a ordem.', 'invalid_transition' => 'Esta mudança de status não é permitida.', 'persona_inactive' => 'A identidade selecionada não está disponível.', 'ready_requires_assignee' => 'Uma ordem pronta precisa ter responsável.', 'revision_conflict' => 'A ordem mudou em outra sessão. Recarregue e tente novamente.'],
    'settings' => ['title' => 'Identidades e contas de publicação', 'subtitle' => 'Gerencie identidades e referências de contas reutilizáveis. Credenciais não são armazenadas aqui.', 'new_persona' => 'Nova identidade', 'new_account' => 'Nova conta', 'name' => 'Nome', 'tone' => 'Tom', 'domain' => 'Especialidade', 'bio' => 'Biografia', 'disclosure' => 'Declaração', 'profile_url' => 'URL do perfil', 'notes' => 'Notas', 'active' => 'Ativa', 'save_persona' => 'Adicionar identidade', 'save_account' => 'Adicionar conta', 'persona_saved' => 'Identidade salva.', 'account_saved' => 'Conta salva.', 'account_count' => ':count conta(s)'],
    'export' => ['id' => 'ID', 'type' => 'Tipo', 'platform' => 'Plataforma', 'article' => 'Artigo', 'persona' => 'Identidade', 'account' => 'Conta', 'assignee' => 'Responsável', 'status' => 'Status', 'scheduled_at' => 'Horário planejado', 'target_url' => 'URL de destino', 'content' => 'Conteúdo', 'risk_status' => 'Risco', 'duplicates' => 'Duplicatas', 'completion_url' => 'URL publicada', 'result_note' => 'Nota', 'created_at' => 'Criada em'],
]);

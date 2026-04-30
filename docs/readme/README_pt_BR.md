# GEOFlow

> Languages: [简体中文](../../README.md) | [English](README_en.md) | [日本語](README_ja.md) | [Español](README_es.md) | [Русский](README_ru.md) | **Português (BR)**

> GEOFlow é um sistema de engenharia de conteúdo inteligente de código aberto, projetado especificamente para GEO (Otimização de Motor de Geração). É uma das primeiras infraestruturas de dados, conteúdo e distribuição do mundo projetadas sistematicamente em torno de fluxos de trabalho GEO, conectando ativos de dados, bases de conhecimento, gerenciamento de materiais, geração de IA, revisão e publicação, apresentação frontend e distribuição futura de múltiplos canais em um pipeline em evolução.

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)
[![License](https://img.shields.io/badge/License-Apache--2.0-blue.svg)](../../LICENSE)
[![GitHub stars](https://img.shields.io/github/stars/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/network/members)
[![GitHub issues](https://img.shields.io/github/issues/yaojingang/GEOFlow)](https://github.com/yaojingang/GEOFlow/issues)

O GEOFlow é lançado sob a [Licença Apache 2.0](../../LICENSE). Você pode usar, copiar, modificar e distribuir, inclusive para fins comerciais, desde que mantenha os avisos de direitos autorais e licença e complies com os termos de patente, marca registrada e exoneração de garantia da Apache-2.0.

---

## ✨ O Que Você Pode Fazer Com Ele

| Recurso | Descrição |
|---------|-----------|
| 🤖 Geração multi-modelo | APIs estilo OpenAI, tipos de modelo chat / embedding, adaptação de URL do provider, failover inteligente e tratamento de retry |
| 📦 Execução de tarefas em lote | Criação de tarefas, limites de geração, frequência de publicação, execução de fila, registros de falha e filtragem de artigos por tarefa |
| 🗂 Gestão unificada de materiais | Bibliotecas de títulos, bibliotecas de palavras-chave, bibliotecas de imagens, biblioteca de autores, bases de conhecimento e prompts |
| 🧠 RAG de base de conhecimento | Faça upload de documentos, gere chunks, escreva vetores quando um modelo de embedding está configurado e recupere contexto relevante durante a geração |
| 📋 Fluxo de revisão e publicação | Estados rascunho, revisão e publicação, auto-publicação opcional, mais filtros de artigos por status, autor e tarefa |
| 🔍 Saída orientada para busca | Metadados SEO, Open Graph, dados estruturados e renderização GFM Markdown para títulos, tabelas, listas e imagens |
| 🎨 Frontend e temas | Tema padrão, pacotes de tema, rotas de preview, troca de tema admin e marca fixa do admin GEOFlow |
| 🌍 I18n do admin | O admin suporta chinês, inglês, japonês, espanhol, russo e português |
| 🔔 Atualizações de versão | O admin pode verificar o `version.json` do GitHub e notificar quando uma versão mais recente está disponível |
| 🐳 Pronto para deploy | **Docker Compose**: PostgreSQL (pgvector), Redis, app, fila, scheduler e Reverb |
| 🗄 Runtime PostgreSQL | PostgreSQL por padrão; adequado para carga estável e writes concorrentes |

---

## 🖼 Preview da Interface

<p>
  <img src="../../docs/images/screenshots/dashboard-en.png" alt="GEOFlow dashboard preview" width="48%" />
  <img src="../../docs/images/screenshots/tasks-en.png" alt="GEOFlow task management preview" width="48%" />
</p>
<p>
  <img src="../../docs/images/screenshots/materials-en.png" alt="GEOFlow materials preview" width="48%" />
  <img src="../../docs/images/screenshots/ai-config-en.png" alt="GEOFlow AI configuration preview" width="48%" />
</p>

Essas telas cobrem a home page, agendamento de tarefas, fluxo de trabalho de artigos e configuração de modelo. Mais documentação do admin está em `../../docs/` (adicione ou substitua screenshots localmente se os caminhos estiverem faltando).

---

## 🆕 Destaques da Nova Versão

Destaques da nova versão incluem:

- **Experiência do admin**: marca fixa do admin GEOFlow, troca multi-idioma, edição/deleção de conta admin, carta de boas-vindas no primeiro login, lembretes de atualização de versão do GitHub e bloco de início rápido do dashboard.
- **Pipeline de tarefas**: modos fixo e smart failover; geração e publicação são separados; links de artigos da tarefa abrem listas de artigos com escopo da tarefa.
- **Sistema de materiais**: bases de conhecimento, bibliotecas de títulos, bibliotecas de palavras-chave, bibliotecas de imagens e autores são todas entradas de admin de primeira classe.
- **Preparação para RAG**: bases de conhecimento são divididas em chunks após upload; modelos de embedding permitem writes vetoriais e recuperação; falta de setup de embedding tem orientação explícita.
- **Setup de modelo**: regras de URL de provider mais claras para APIs estilo OpenAI, Zhipu, Volcengine Ark e outros providers não-`/v1`.

---

## 🏗 Fluxo de Execução

```
Admin
  ↓
Scheduler / Fila (opcional Horizon)
  ↓
Worker — chamada IA
  ↓
Rascunho / Revisão / Publicação
  ↓
Frontend
```

---

## 🧱 Arquitetura

| Camada | Descrição |
|--------|------------|
| Web / Admin | **Laravel**: rotas, controllers, **Blade** para admin e artigos |
| API | `routes/api.php` e outros (autenticação conforme configuração do projeto) |
| Scheduler / Fila / Reverb | **Scheduler**, **`queue:work` / Horizon**, se necessário **Reverb** |
| Domínio e Jobs | `app/Services`, `app/Jobs`, `app/Http/Controllers` etc. |
| Armazenamento | **PostgreSQL** (recomendado **pgvector**) + **Redis** |

Fluxo principal: configuração de modelos e prompts → preparação de base de conhecimento, títulos, palavras-chave, imagens e autores → tarefas na fila → workers geram conteúdo → rascunho / revisão / publicação → páginas frontend com SEO.

---

## ⚡ Início Rápido no Admin

1. **Configure a API**: adicione pelo menos um modelo de chat; para RAG, adicione um modelo de embedding.
2. **Configure materiais**: prepare base de conhecimento, títulos, palavras-chave, imagens e autores com base em informações reais e verificáveis.
3. **Crie uma tarefa**: escolha materiais, modelo, volume de geração e frequência de publicação; primeiro teste o fluxo via rascunhos ou revisão.

---

## 🚀 Deploy com Docker Compose

### Configuração Rápida

1. Clone o projeto:
```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow
```

2. Copie o arquivo de ambiente:
```bash
cp .env.example .env
```

3. Inicie os containers:
```bash
docker compose up -d
```

Acesse `http://localhost:18080` (frontend) e `http://localhost:18080/geo_admin` (admin).

Para produção, configure `.env.prod` e use `docker compose -f docker-compose.prod.yml up -d`.

### portas

| Serviço | Porta |
|---------|-------|
| App (development) | 18080 |
| App (production nginx) | 18080 |
| Postgres | 15432 |
| Redis | 16379 |
| Reverb | 18081 |

---

## 📖 Documentação

- [Documentação completa](docs/README.md)
- [Changelog](docs/CHANGELOG.md)

---

## ❤️ Agradecimentos

- [Laravel](https://laravel.com/) - O framework PHP
- [Laravel AI SDK](https://laravel.com/ai) - Integração com AI
- [Laravel Horizon](https://laravel.com/horizon) - Gerenciamento de fila
- [Laravel Reverb](https://laravel.com/reverb) - WebSocket
- [pgvector](https://github.com/pgvector/pgvector) - Vetores no PostgreSQL

---

## 📄 Licença

GEOFlow é software livre sob a [Licença Apache 2.0](LICENSE).

---

## 👨‍💻 Stack Técnica

<p>
  <img src="https://img.shields.io/badge/PHP-8.4-blue" alt="PHP 8.4" />
  <img src="https://img.shields.io/badge/Laravel-12-blue" alt="Laravel 12" />
  <img src="https://img.shields.io/badge/Docker-29.4-blue" alt="Docker 29.4" />
  <img src="https://img.shields.io/badge/PostgreSQL-16-blue" alt="PostgreSQL 16" />
  <img src="https://img.shields.io/badge/Redis-7-blue" alt="Redis 7" />
</p>

Baseado em PHP 8.4, Laravel 12, PostgreSQL 16 (pgvector), Redis 7, Docker e Docker Compose.

---

<p align="center">
  <a href="https://github.com/yaojingang/GEOFlow">
    <img src="https://img.shields.io/github/stars/yaojingang/GEOFlow?style=flat" alt="GitHub Stars" />
  </a>
  <a href="https://github.com/yaojingang/GEOFlow">
    <img src="https://img.shields.io/github/forks/yaojingang/GEOFlow?style=flat" alt="GitHub Forks" />
  </a>
  <a href="https://github.com/yaojingang/GEOFlow/issues">
    <img src="https://img.shields.io/github/issues/yaojingang/GEOFlow?style=flat" alt="GitHub Issues" />
  </a>
</p>
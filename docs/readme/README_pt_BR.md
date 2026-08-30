# GEOFlow

> Languages: [简体中文](../../README.md) | [English](README_en.md) | [日本語](README_ja.md) | [Español](README_es.md) | [Русский](README_ru.md) | **Português (BR)**

> GEOFlow é um sistema open source de engenharia de conteúdo GEO (Generative Engine Optimization) e distribuição multi-site. Ele conecta bases de conhecimento, bibliotecas de materiais, prompts, tarefas de geração por IA, revisão e publicação, analytics, pacotes de sites-alvo GEOFlow Agent, canais WordPress REST, canais HTTP API genéricos e distribuição remota de páginas estáticas para transformar materiais confiáveis em ativos GEO publicáveis, rastreáveis e distribuíveis.

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)
[![License](https://img.shields.io/badge/License-AGPL--3.0-blue.svg)](../../LICENSE)
[![GitHub stars](https://img.shields.io/github/stars/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/network/members)
[![GitHub issues](https://img.shields.io/github/issues/yaojingang/GEOFlow)](https://github.com/yaojingang/GEOFlow/issues)

O GEOFlow é distribuído sob a [GNU Affero General Public License v3.0](../../LICENSE). Pessoas e organizações podem usá-lo, modificá-lo, implantá-lo e distribuí-lo em conformidade com a AGPL-3.0. Uma versão modificada oferecida a usuários por rede deve disponibilizar a esses usuários o código-fonte correspondente conforme a licença. O titular dos direitos autorais pode conceder uma licença comercial separada para modificações proprietárias, distribuição white-label ou OEM, integração em produtos proprietários e outros usos que não possam cumprir a AGPL-3.0.

---

## ✨ O Que Você Pode Fazer Com Ele

| Recurso | Descrição |
|---------|-----------|
| 🤖 Geração multi-modelo | APIs estilo OpenAI e endpoints nativos Gemini, modelos chat / embedding, adaptação de URL, failover inteligente, retries e estatísticas de uso |
| 🧠 RAG de base de conhecimento | Chunking por regras, planejamento semântico opcional com LLM, fallback estável, vetores com embedding e recuperação de contexto durante a geração |
| 🗂 Materiais e prompts | Títulos, palavras-chave, imagens, autores, bases de conhecimento, prompts de corpo e prompts especiais |
| 📦 Automação de tarefas | Limites de geração, pool de rascunhos, revisão, cadência de publicação, filas, retries, escopo de publicação e filtros por tarefa |
| 📋 Revisão e artigos | Rascunhos, revisão, publicação, lixeira, autores, categorias, SEO e origem da tarefa em um único fluxo |
| 📡 Distribuição multi-site | Canais GEOFlow Agent, WordPress REST e HTTP API genéricos, segredos, pacotes de site-alvo, modo estático, regras rewrite, edição/exclusão remota, filas e logs |
| 🧾 Pacotes de site-alvo | PHP Agent por canal com home, páginas de artigo, assets estáticos, sitemap, `llms.txt` / mapas TXT e Schema |
| 📊 Analytics | Visão global, operação de site único, distribuição multi-site, logs de acesso, top conteúdos, crawlers de IA e tendências |
| 🔍 Saída SEO e LLM-friendly | SEO, Open Graph, Schema, Markdown GFM, CSS independente, sincronização de imagens, sitemap e mapas TXT |
| 🎨 Frontend e temas | Temas, preview, troca pelo admin e sincronização remota de título, copyright, tema e categorias |
| 🌍 I18n do admin | Chinês, inglês, japonês, espanhol, russo e português, incluindo os módulos do Admin UI V3 |
| 🔔 Atualizações de versão | O admin pode verificar o `version.json` do GitHub e notificar quando uma versão mais recente está disponível |
| 🐳 Pronto para deploy | **Docker Compose**: PostgreSQL (pgvector), Redis, app, fila, scheduler, Reverb e produção com Nginx/php-fpm |

---

## GEOFlow CLI 0.2.0

O repositório inclui `bin/geoflow` para gerenciar catálogos, tarefas, trabalhos, materiais e artigos pela API v1. O suporte oficial cobre macOS, Linux e WSL. No Windows nativo, confirme manualmente as ACLs do arquivo de configuração.

[Guia completo da CLI em inglês](../GEOFLOW_CLI_en.md)

---

## 🖼 Preview da Interface

<table>
  <tr>
    <td width="34%" rowspan="3"><img src="../../docs/images/screenshots/analytics-en.png" alt="GEOFlow analytics preview" /><br /><sub>Analytics</sub></td>
    <td width="33%" rowspan="2"><img src="../../docs/images/screenshots/site-settings-en.png" alt="GEOFlow site settings preview" /><br /><sub>Site Settings</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/dashboard-en.png" alt="GEOFlow admin dashboard preview" /><br /><sub>Admin Dashboard</sub></td>
  </tr>
  <tr>
    <td width="33%"><img src="../../docs/images/screenshots/tasks-en.png" alt="GEOFlow task management preview" /><br /><sub>Task Management</sub></td>
  </tr>
  <tr>
    <td width="33%"><img src="../../docs/images/screenshots/ai-config-en.png" alt="GEOFlow AI model configuration preview" /><br /><sub>AI Model Configuration</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/materials-en.png" alt="GEOFlow materials preview" /><br /><sub>Materials</sub></td>
  </tr>
</table>

Essas telas cobrem o painel admin, analytics, tarefas, materiais, configuração de modelos e ajustes do site.

---

## 🆕 Destaques da Nova Versão

O GEOFlow 3.0 é uma atualização principal do admin, da IA, da distribuição e dos fluxos operacionais:

- **O Admin UI V3 unifica toda a interface**: as páginas compartilham nova barra lateral, barra superior, navegação e padrões de interação, com atividade recente, largura ajustável, layouts responsivos, estados acessíveis e recursos locais.
- **O AI Workspace agora é um assistente ilustrado do admin**: o conhecimento do sistema cobre 15 temas, com 24 capturas sanitizadas e 72 perguntas fixas de avaliação. O conteúdo é transmitido por SSE e os recursos respeitam as permissões do administrador atual. O fluxo anterior de Run, Plan, Approval, Capability e Trace deixa de receber novas solicitações, preservando os dados históricos.
- **A inspeção de qualidade por IA funciona como porta de publicação**: avalia evidências, regras de publicidade e contexto, retornando pontuações, localização no texto, referências legais, recomendações e liberações auditáveis. Artigos longos continuam por segmentos na fila.
- **Sites de canal hospedados têm um ciclo de vida completo**: alocação de subdomínios, gestão de estados, associação de artigos, cotas, períodos de espera, preflight técnico, invalidação de cache e reconciliação seguem limites de host principal e proxy confiável.
- **A Publicação Manual se conecta ao assistente de operações para Chrome**: a extensão usa pareamento de dispositivo e tokens de privilégio mínimo para assumir trabalhos, enviar heartbeats, validar contas, preencher rascunhos de respostas em texto no Zhihu e devolver evidências. A publicação final continua sob confirmação do usuário.
- **O admin pode ser instalado como PWA**: ícones locais, manifesto, Service Worker e fluxo de atualização oferecem uma área de trabalho independente.
- **A configuração de tarefas e modelos ficou mais segura**: capacidade e conflitos da biblioteca de títulos são verificados antes da ativação; testes de modelo registram streaming real, fallback de texto, failover e estados completos de prontidão.
- **Operações longas de conteúdo podem ser retomadas**: bibliotecas geram até 100 mil títulos em lotes, a lixeira de tarefas mantém 90 dias de auditoria e artigos selecionados são exportados em um ZIP de Markdown.
- **API e CLI cobrem operações rotineiras**: API v1 e `bin/geoflow` gerenciam catálogos, tarefas, execuções, materiais, artigos e operações do navegador com entrada e saída estruturadas e verificações de segurança.
- **O atualizador independente executa operações de alto risco**: uma API local por Unix socket solicita atualizações, backups completos, validação do ambiente e restauração. A versão compatível assinada é vinculada ao commit final e aos digests das imagens do GEOFlow 3.0.
- **Os limites de instalação e deploy estão mais claros**: novas instalações começam apenas com os dados necessários e não importam artigos de demonstração. Atualizações executam migrações, recompilam o frontend e reiniciam processos; sites hospedados são ativados depois da configuração de DNS curinga, TLS curinga, proxies confiáveis e Nginx.

---

## 🏗 Fluxo de Execução

```
Admin
  ↓
Configuração IA / materiais / prompts / tarefas
  ↓
Scheduler / fila / worker executa IA
  ↓
Rascunho / Revisão / Publicação
  ↓
Artigos locais e páginas SEO
  ↓
Fila de distribuição / Agent do site-alvo
  ↓
Home remota, artigos, sitemap, mapas TXT e llms.txt
```

---

## 🧱 Arquitetura

| Camada | Descrição |
|--------|------------|
| Web / Admin | **Laravel**: rotas, controllers, site de artigos, **Blade** admin, analytics, distribuição, materiais e tarefas |
| API / Agent | APIs locais e PHP Agent de sites-alvo para health check, receber/atualizar/excluir artigos, sincronizar configurações e gerar estáticos |
| Scheduler / Fila / Reverb | **Scheduler**, **`queue:work` / Horizon** para geração e distribuição, se necessário **Reverb** |
| Domínio e Jobs | `app/Services`, `app/Jobs`, `app/Http/Controllers` para IA, RAG, publicação, distribuição e análise de logs |
| Armazenamento | **PostgreSQL** (recomendado **pgvector**) + **Redis** + JSON/arquivos estáticos nos sites-alvo |

Fluxo principal: configuração de modelos e prompts → preparação de base de conhecimento, títulos, palavras-chave, imagens e autores → tarefas na fila → workers geram conteúdo → rascunho / revisão / publicação → páginas SEO locais → distribuição para canais selecionados → analytics de produção, distribuição, acesso e crawlers de IA.

---

## ⚡ Início Rápido no Admin

1. **Configure a API**: adicione pelo menos um modelo de chat; para RAG, adicione um modelo de embedding e escolha uma estratégia de chunking.
2. **Configure materiais**: prepare base de conhecimento, títulos, palavras-chave, imagens e autores com base em informações reais e verificáveis.
3. **Crie uma tarefa**: escolha materiais, modelo, volume de geração, frequência e escopo de publicação; primeiro teste o fluxo via rascunhos ou revisão.

---

## 🎯 Casos de Uso e Resultados Esperados

O GEOFlow é adequado para estes cenários práticos:

- **Site GEO independente**
  Organize conteúdo de produto, FAQs, casos e conhecimento de marca em um sistema sustentável. O objetivo é melhorar visibilidade em buscas com IA e eficiência operacional, não criar páginas fracas em massa.
- **Subcanal GEO em um site oficial**
  Adicione um canal de notícias, conhecimento ou soluções dentro de um site existente. Estruture o conteúdo para busca, citações e atualização em equipe.
- **Site-fonte GEO independente**
  Publique explicações, rankings, guias e referências de qualidade sobre um setor ou tema. Construa ativos confiáveis, não ruído na web.
- **Gestão interna de conteúdo GEO**
  Use como backend de produção para modelos, materiais, conhecimento, revisão e publicação. Reduza a dispersão de ferramentas e aumente a eficiência da equipe.
- **GEO multi-site / multi-seção**
  Opere múltiplos canais, marcas ou modelos com um padrão operacional comum.
- **Gestão automatizada de fontes e distribuição**
  Estruture bases de conhecimento, atualizações temáticas e distribuição para manter informação valiosa recuperável.

O valor deve partir de uma **base de conhecimento real, confiável e mantida continuamente**.
O GEOFlow não deve ser usado para fabricar ruído, poluir a internet ou publicar afirmações falsas. Ele existe para ajudar equipes a produzir e distribuir conteúdo **confiável** e melhorar a eficiência operacional de GEO.

---

## 🧭 Padrões Sugeridos de Deploy e Uso

- **Como site GEO independente**
  Publique frontend e admin completos; opere produtos, FAQ, casos e temas como uma propriedade própria.
- **Como subcanal GEO**
  Use subdiretório, subdomínio ou canal lateral sem reconstruir o site principal.
- **Como site-fonte GEO**
  Priorize a base de conhecimento e use tarefas para atualizações controladas e contínuas.
- **Como backend GEO interno**
  Dê menos foco ao site público e concentre-se em admin, modelos, materiais, agendamento, revisão e APIs.
- **Como sistema multi-site ou multicanal**
  Reutilize workflows entre marcas, temas e experimentos.
- **Como camada de gestão automatizada de fontes**
  Trate bibliotecas de títulos, imagens, prompts e conhecimento como infraestrutura de longo prazo.

Ordem recomendada:

1. Defina objetivos reais e público-alvo
2. Construa a base de conhecimento antes de automatizar em escala
3. Mantenha o conteúdo correto, verificável e sustentável
4. Só depois escale com modelos, tarefas e templates

Uma base de conhecimento fraca com automação forte apenas escala ruído. No GEOFlow, **a qualidade da base de conhecimento vem primeiro**.

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

Para a primeira instalação em um banco vazio, configure `.env.prod` e use `docker compose --env-file .env.prod -f docker-compose.prod.yml up -d`. O serviço `init` executa as migrações e depois `php artisan geoflow:install`. Instâncias com dados ou histórico de migrações devem seguir o protocolo de parada e drenagem da seção 3.1 em `../deployment/DEPLOYMENT.md`.

### portas

| Serviço | Porta |
|---------|-------|
| App (development) | 18080 |
| App (production nginx) | 18080 |
| Postgres | 15432 |
| Redis | 16379 |
| Reverb | 18081 |

---

## 🧩 Notas de Deploy por Código-Fonte

```bash
chmod -R ug+rwx storage bootstrap/cache
```

**Admin padrão** após `geoflow:install`:

| Campo | Valor |
|-------|-------|
| Usuário | `GEOFLOW_ADMIN_USERNAME`, padrão `admin` |
| Senha | Em desenvolvimento local, o padrão é `password`; em produção defina `GEOFLOW_ADMIN_PASSWORD`. Se ficar vazio e a conta ainda não existir, o instalador gera uma senha aleatória de uso único nos logs de init / `geoflow:install`. |

`geoflow:install` só executa seeders iniciais quando o banco está vazio. Se detectar dados de usuário ou de negócio, apenas grava o marcador de instalação e ignora o seed. O admin seeder continua idempotente e nunca sobrescreve usuário, email ou senha existentes.

A instalação normal e o comando `db:seed` não executam `FrontendDemoSeeder`. Os dados de demonstração ficam restritos a testes que chamam o seeder explicitamente; mantenha `GEOFLOW_SEED_FRONTEND_DEMO=false` e `GEOFLOW_SEED_FRONTEND_DEMO_OVERWRITE=false` em qualquer ambiente implantado.

### Bloqueio de login admin e desbloqueio manual

- Contas admin são bloqueadas após **5** tentativas consecutivas de login inválido.
- Contas bloqueadas precisam ser desbloqueadas por um administrador.
- Comando de desbloqueio:

```bash
php artisan geoflow:admin-unlock USERNAME
```

**HTTP em produção:** use Nginx/Apache + **PHP-FPM**, com document root em **`public/`**. Não exponha a raiz do projeto como web root.

---

## 🐳 Notas de Deploy Docker

### Serviços do Compose de desenvolvimento

| Serviço | Papel |
|---------|-------|
| `postgres` | PostgreSQL 16 + pgvector |
| `redis` | Redis 7 |
| `assets` | Instala as dependências do frontend e gera os assets Vite uma vez |
| `init` | Bootstrap único (`restart: "no"`) |
| `app` | Executa `php artisan serve` apenas na rede interna |
| `web` | Gateway Nginx unificado em **`127.0.0.1:${APP_PORT:-18080}:80`** |
| `queue` | Worker da área de trabalho de IA e das filas da aplicação |
| `scheduler` | `schedule:work` |
| `reverb` | Serviço WebSocket interno, encaminhado pelo Nginx em **`/reverb`** na mesma origem |

Para produção, use a pilha **`docker-compose.prod.yml`** com Nginx + php-fpm e consulte `../deployment/DEPLOYMENT.md`.

**Atualização de uma instalação existente:** não execute diretamente `git pull` → `build` → `up -d`. Siga o protocolo de parada, drenagem, migração e readiness da [seção 3.1 de deployment](../deployment/DEPLOYMENT.md#31-受管图片删除升级门禁).

---

## Desenvolvimento e Testes

```bash
composer test
./vendor/bin/pint
```

---

## 📖 Documentação

- [Documentação completa](../../README.md)
- [Changelog](../CHANGELOG.md)

---

## ❤️ Agradecimentos

- [Laravel](https://laravel.com/) - O framework PHP
- [Laravel AI SDK](https://laravel.com/ai) - Integração com AI
- [Laravel Horizon](https://laravel.com/horizon) - Gerenciamento de fila
- [Laravel Reverb](https://laravel.com/reverb) - WebSocket
- [pgvector](https://github.com/pgvector/pgvector) - Vetores no PostgreSQL

---

## 📄 Licença

GEOFlow é software livre sob a [GNU Affero General Public License v3.0](../../LICENSE). Versões anteriormente publicadas sob Apache-2.0 mantêm a licença original; termos comerciais separados podem ser solicitados ao titular dos direitos autorais para usos que não possam cumprir a AGPL-3.0.

Antes de enviar código, documentação, designs ou outros materiais originais, leia o [guia de contribuição](../../CONTRIBUTING.md). Contribuições protegidas por direitos autorais exigem a aceitação do [GEOFlow Contributor License Agreement v1.0](../../CLA.md) antes da incorporação.

---

## 🌍 README em Outros Idiomas

- [简体中文](../../README.md)
- [English](README_en.md)
- [日本語](README_ja.md)
- [Español](README_es.md)
- [Русский](README_ru.md)

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

## ⭐ Histórico de Stars

[![Star History Chart](https://api.star-history.com/svg?repos=yaojingang/GEOFlow&type=Date)](https://star-history.com/#yaojingang/GEOFlow&Date)

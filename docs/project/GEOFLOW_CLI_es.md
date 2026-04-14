# Guía CLI de GEOFlow

> Languages: [简体中文](GEOFLOW_CLI.md) | [English](GEOFLOW_CLI_en.md) | [日本語](GEOFLOW_CLI_ja.md) | [Español](GEOFLOW_CLI_es.md) | [Русский](GEOFLOW_CLI_ru.md)

`geoflow` es la entrada CLI local de la primera fase de la API.

Solo se comunica con GEOFlow a través de `/api/v1`. No accede directamente a la base de datos ni reutiliza la sesión web del admin.

## 1. Entrada

```bash
./bin/geoflow help
```

## 2. Prioridad de configuración

Fuentes soportadas:

1. flags de CLI
2. variables de entorno
3. archivos de configuración

Prioridad:

`CLI flags > variables de entorno > .geoflow.json > ~/.config/geoflow/config.json`

## 3. Primer login recomendado

```bash
./bin/geoflow \
  login \
  --base-url http://127.0.0.1:18080 \
  --username admin
```

Si omites `--password`, el CLI lo pedirá de forma segura.

Si ya tienes token, también puedes inicializar manualmente:

```bash
./bin/geoflow \
  config init \
  --base-url http://127.0.0.1:18080 \
  --token gf_xxx
```

Ver configuración:

```bash
./bin/geoflow config show
```

## 4. Comandos comunes

Catálogo:

```bash
./bin/geoflow catalog
```

Listar tareas:

```bash
./bin/geoflow task list --status active
```

Crear tarea:

```bash
./bin/geoflow task create --json ./task.json
```

Iniciar y encolar:

```bash
./bin/geoflow task start 12
./bin/geoflow task enqueue 12
```

Consultar jobs:

```bash
./bin/geoflow task jobs 12 --limit 20
./bin/geoflow job get 88
```

Crear artículo:

```bash
./bin/geoflow article create \
  --title "CLI test article" \
  --content-file ./article.md \
  --task-id 12 \
  --author-id 5 \
  --category-id 2
```

Revisar y publicar:

```bash
./bin/geoflow article review 101 --status approved --note "CLI review pass"
./bin/geoflow article publish 101
```

## 5. Ejemplos JSON

Creación de tarea:

```json
{
  "name": "CLI Task Integration Test",
  "title_library_id": 1,
  "prompt_id": 6,
  "ai_model_id": 1,
  "knowledge_base_id": 1,
  "author_id": 5,
  "image_library_id": null,
  "image_count": 0,
  "need_review": true,
  "publish_interval": 3600,
  "auto_keywords": true,
  "auto_description": true,
  "draft_limit": 3,
  "is_loop": false,
  "status": "paused",
  "category_mode": "smart",
  "fixed_category_id": null
}
```

Creación de artículo:

```json
{
  "title": "CLI Article Test",
  "content": "# CLI Article Test\n\nThis article was created through the CLI.",
  "task_id": 12,
  "author_id": 5,
  "category_id": 2,
  "status": "draft",
  "review_status": "pending",
  "keywords": "cli,test",
  "meta_description": "CLI article integration test"
}
```

## 6. Clave de idempotencia

Todas las escrituras soportan:

```text
--idempotency-key <key>
```

## 7. Alcance actual

Cobertura actual:

- `login`
- `catalog`
- `task list/create/get/update/start/stop/enqueue/jobs`
- `job get`
- `article list/create/get/update/review/publish/trash`

Todavía no cubre:

- importación de URL
- generación asíncrona de títulos
- orquestación de subida de imágenes
- flujos batch de nivel superior

# GEOFlow FAQ

> Languages: [简体中文](FAQ.md) | [English](FAQ_en.md) | [日本語](FAQ_ja.md) | [Español](FAQ_es.md) | [Русский](FAQ_ru.md)

## 1. ¿Cuál es la URL y la cuenta admin por defecto?

- URL admin: `/geo_admin/`
- Usuario por defecto: `admin`
- Contraseña por defecto: `admin888`

Cambia la contraseña del administrador y `APP_SECRET_KEY` después del primer acceso.

## 2. ¿Es obligatorio usar Docker?

No.

- Puedes arrancar `web + postgres + scheduler + worker` con Docker Compose
- o usar PHP local con PostgreSQL y `php -S`

Si quieres el camino más corto, usa Docker.

## 3. ¿La base de datos en tiempo de ejecución es PostgreSQL?

Sí. La versión pública usa PostgreSQL como runtime.

## 4. ¿Por qué el repositorio no incluye imágenes, conocimiento o artículos?

Porque son datos de ejecución o de negocio:

- bibliotecas de imágenes
- archivos fuente de la base de conocimiento
- artículos generados
- logs y backups

El repositorio público solo incluye código fuente y plantillas de configuración.

## 5. ¿Cómo conecto un modelo de IA?

En el panel admin, ve a `AI Configuration Center → AI Model Management` y completa:

- URL de API
- ID de modelo
- Bearer token

GEOFlow espera una API compatible con OpenAI.

## 6. ¿Cuál es el flujo de generación de artículos?

1. Configurar modelos, prompts y materiales
2. Crear una tarea
3. El scheduler encola jobs
4. El worker llama a la IA
5. Borrador / revisión / publicación
6. El front-end renderiza el artículo

## 7. ¿Hay CLI o skill?

Sí.

- Guía CLI: [project/GEOFLOW_CLI_es.md](project/GEOFLOW_CLI_es.md)
- Repositorio del skill: [yaojingang/yao-geo-skills](https://github.com/yaojingang/yao-geo-skills)
- Skill: `geoflow-cli-ops`

## 8. ¿Qué documentos conviene leer primero para extender el sistema?

- [系统说明文档](系统说明文档.md)
- [AI_PROJECT_GUIDE.md](AI_PROJECT_GUIDE.md)
- [project/STRUCTURE.md](project/STRUCTURE.md)
- [project/API_V1_REFERENCE_DRAFT.md](project/API_V1_REFERENCE_DRAFT.md)

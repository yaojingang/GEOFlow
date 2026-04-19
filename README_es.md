# GEOFlow

> Languages: [简体中文](README.md) | [English](README_en.md) | [日本語](README_ja.md) | [Español](README_es.md) | [Русский](README_ru.md)

> Sistema open source de producción de contenido para operaciones GEO / SEO. Conecta la configuración de modelos, la gestión de materiales, la ejecución de tareas, la revisión y la publicación en una sola cadena de trabajo.

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)
[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE)

Released under the Apache License 2.0.

## Vista previa de la interfaz

<p>
  <img src="docs/images/screenshots/dashboard-en.png" alt="Vista previa del panel de GEOFlow" width="48%" />
  <img src="docs/images/screenshots/tasks-en.png" alt="Vista previa de la gestión de tareas de GEOFlow" width="48%" />
</p>
<p>
  <img src="docs/images/screenshots/materials-en.png" alt="Vista previa de la gestión de materiales de GEOFlow" width="48%" />
  <img src="docs/images/screenshots/ai-config-en.png" alt="Vista previa del configurador de IA de GEOFlow" width="48%" />
</p>

Estas cuatro pantallas cubren la página principal, la programación de tareas, el flujo de artículos y la configuración de modelos. El resto de las páginas del panel se mantiene documentado en `docs/`.

## Qué hace GEOFlow

- Ejecuta tareas de generación de contenido GEO / SEO con IA
- Gestiona bibliotecas de títulos, prompts, imágenes y conocimiento
- Soporta flujo borrador → revisión → publicación
- Expone API y CLI para automatización
- Publica páginas de artículos con metadatos SEO

## 🎯 Escenarios de uso y beneficios esperados

GEOFlow encaja bien en estos escenarios reales:

- **Sitio GEO independiente**  
  Para operar un sitio centrado en FAQs, contenido de producto, casos y conocimiento de marca. El objetivo es mejorar la visibilidad en búsqueda por IA y la eficiencia operativa, no producir páginas de bajo valor.
- **Subcanal GEO dentro de un sitio oficial**  
  Para añadir un canal de noticias, conocimiento o soluciones dentro de un sitio ya existente. El objetivo es estructurar mejor el contenido y facilitar su mantenimiento.
- **Sitio independiente de fuente GEO**  
  Para acumular guías, rankings, análisis y artículos alrededor de un tema o sector concreto. El objetivo es construir activos de contenido confiables, no contaminar internet con ruido.
- **Sistema interno de gestión de contenido GEO**  
  Para usar GEOFlow como backend interno de modelos, materiales, prompts, conocimiento, revisión y publicación. El objetivo es aumentar la eficiencia del equipo.
- **Despliegue GEO multi-sitio o multi-canal**  
  Para gestionar varios sitios, canales o temas con un mismo patrón operativo. El objetivo es estandarizar la producción y distribución de contenido.

El valor del sistema debe basarse en una **base de conocimiento real, de calidad y bien mantenida**.  
GEOFlow no está pensado para fabricar información falsa ni para saturar la web. Su propósito es mejorar la eficiencia del marketing con IA y de la operación GEO mediante contenido confiable.

## 🧭 Formas recomendadas de despliegue y uso

- **Como sitio GEO independiente**  
  Despliega frontend y panel admin completos y úsalo como propiedad editorial independiente.
- **Como subcanal GEO de un sitio existente**  
  Úsalo bajo un subdominio, directorio o canal especializado sin reconstruir todo el sitio principal.
- **Como sitio fuente GEO**  
  Prioriza primero la construcción de la base de conocimiento y después automatiza las actualizaciones mediante tareas.
- **Como backend interno de contenido GEO**  
  Aprovecha el panel, los modelos, los materiales, la cola, la API, el CLI y los Skills como infraestructura de producción interna.
- **Como sistema multi-sitio o multi-canal**  
  Reutiliza flujos, plantillas y procesos para varios canales, marcas o experimentos.

Orden recomendado:

1. Definir primero el objetivo real y el público real  
2. Construir primero la base de conocimiento  
3. Garantizar que el contenido sea verificable y mantenible  
4. Solo después usar la automatización para ganar eficiencia  

Si la base de conocimiento es débil, la automatización solo amplificará el ruido. En GEOFlow, **la calidad de la base de conocimiento debe ir primero**.

## Inicio rápido

### Docker

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow
cp .env.example .env
docker compose --profile scheduler up -d --build
```

- Front-end: `http://localhost:18080`
- Panel admin: `http://localhost:18080/geo_admin/`

### PHP local + PostgreSQL

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow

export DB_DRIVER=pgsql
export DB_HOST=127.0.0.1
export DB_PORT=5432
export DB_NAME=geo_system
export DB_USER=geo_user
export DB_PASSWORD=geo_password

php -S localhost:8080 router.php
```

## Credenciales iniciales

- Usuario: `admin`
- Contraseña: `admin888`

Cambia la contraseña del administrador y `APP_SECRET_KEY` después del primer acceso.

## Estructura de ejecución

```text
Panel admin
  ↓
Scheduler / Queue
  ↓
Worker ejecuta la generación con IA
  ↓
Borrador / Revisión / Publicación
  ↓
Salida en el front-end
```

## Directorios principales

- `admin/` panel de administración
- `api/v1/` entrada de la API
- `bin/` CLI, scheduler, worker y scripts de mantenimiento
- `docker/` configuración de contenedores
- `docs/` documentación pública
- `includes/` servicios principales y reglas de negocio

## Skill complementario

- Repositorio: [yaojingang/yao-geo-skills](https://github.com/yaojingang/yao-geo-skills)
- Ruta: `skills/geoflow-cli-ops`

## Documentación

- [Docs index](docs/README_es.md)
- [FAQ](docs/FAQ_es.md)
- [Deployment](docs/deployment/DEPLOYMENT_es.md)
- [CLI guide](docs/project/GEOFLOW_CLI_es.md)

## Alcance del repositorio público

- Incluye código fuente, plantillas de configuración y documentación pública
- No incluye bases de datos productivas, archivos subidos ni claves API reales
- Está pensado para despliegue self-hosted y desarrollo secundario

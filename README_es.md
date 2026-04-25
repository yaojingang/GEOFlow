# GEOFlow

> Languages: [简体中文](README.md) | [English](README_en.md) | [日本語](README_ja.md) | [Español](README_es.md) | [Русский](README_ru.md)

> Sistema open source de producción de contenido para operaciones GEO / SEO. **Este repositorio es la edición Laravel 12**: modelos y materiales, programación de tareas, colas y monitorización, revisión y publicación en un solo flujo.

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20)](https://laravel.com/)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![GitHub stars](https://img.shields.io/github/stars/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/network/members)
[![GitHub issues](https://img.shields.io/github/issues/yaojingang/GEOFlow)](https://github.com/yaojingang/GEOFlow/issues)

El esqueleto del framework se publica bajo [MIT License](https://opensource.org/licenses/MIT) (véase `composer.json`). El código de negocio sigue la licencia indicada en la raíz del repositorio.

---

## ✨ Qué puedes hacer

| Característica | Descripción |
|----------------|-------------|
| 🤖 Generación multi-modelo | APIs estilo OpenAI y varios proveedores |
| 📦 Tareas por lotes | Creación, programación, cola, reintentos; **Horizon** opcional |
| 🗂 Materiales unificados | Títulos, palabras clave, imágenes, conocimiento, prompts |
| 📋 Revisión y publicación | Borrador → revisión → publicación; auto-publicación opcional |
| 🔍 Orientado a búsqueda | Metadatos SEO, Open Graph, datos estructurados |
| 🎨 Front y temas | Sitio de artículos y ajustes en el panel |
| ⚡ Tiempo real | **Laravel Reverb** (activar en `.env`) |
| 🐳 Listo para desplegar | **Docker Compose**: Postgres (pgvector), Redis, app, cola, scheduler, Reverb |
| 🗄 PostgreSQL | Base por defecto para carga estable y escrituras concurrentes |

---

## 🖼 Vista previa de la interfaz

<p>
  <img src="docs/images/screenshots/dashboard-en.png" alt="Panel GEOFlow" width="48%" />
  <img src="docs/images/screenshots/tasks-en.png" alt="Tareas GEOFlow" width="48%" />
</p>
<p>
  <img src="docs/images/screenshots/materials-en.png" alt="Materiales GEOFlow" width="48%" />
  <img src="docs/images/screenshots/ai-config-en.png" alt="Configuración IA GEOFlow" width="48%" />
</p>

Cubre inicio, tareas, flujo de artículos y modelos. Si faltan imágenes en `docs/`, añádelas localmente.

---

## 🏗 Estructura de ejecución

```
Panel admin
  ↓
Programador / cola (Horizon opcional)
  ↓
Worker ejecuta la IA
  ↓
Borrador / revisión / publicación
  ↓
Salida en el front-end
```

---

## 🧱 Arquitectura del sistema

| Capa | Descripción |
|------|-------------|
| Web / Admin | **Laravel**: rutas, controladores, **Blade** para admin y artículos |
| API | `routes/api.php` (autenticación según configuración) |
| Scheduler / cola / Reverb | **Scheduler**, **`queue:work` / Horizon**, **Reverb** si aplica |
| Dominio y Jobs | `app/Services`, `app/Jobs`, `app/Http/Controllers`, etc. |
| Persistencia | **PostgreSQL** (recomendado **pgvector**) + **Redis** |

Flujo principal: configurar en admin → crear tareas y encolar → workers generan contenido → borrador / revisión / publicación → front con SEO.

---

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
- **Gestión automatizada de fuentes y distribución**  
  Para tratar bases de conocimiento, actualizaciones editoriales y distribución como ingeniería de contenido. El objetivo es que la información valiosa sea más estable, comprensible y recuperable.

El valor del sistema debe basarse en una **base de conocimiento real, de calidad y bien mantenida**.  
GEOFlow no está pensado para fabricar información falsa ni para saturar la web. Su propósito es mejorar la eficiencia del marketing con IA y de la operación GEO mediante contenido confiable.

---

## 🧭 Formas recomendadas de despliegue y uso

- **Como sitio GEO independiente**  
  Despliega frontend y panel admin completos y úsalo como propiedad editorial independiente.
- **Como subcanal GEO de un sitio existente**  
  Úsalo bajo un subdominio, directorio o canal especializado sin reconstruir todo el sitio principal.
- **Como sitio fuente GEO**  
  Prioriza primero la construcción de la base de conocimiento y después automatiza las actualizaciones mediante tareas.
- **Como backend interno de contenido GEO**  
  Aprovecha el panel, los modelos, los materiales, la cola, la API y los procesos editoriales como infraestructura interna.
- **Como sistema multi-sitio o multi-canal**  
  Reutiliza flujos, plantillas y procesos para varios canales, marcas o experimentos.
- **Como sistema de gestión automatizada de fuentes**  
  Trata bibliotecas de títulos, imágenes y prompts, y la base de conocimiento, como infraestructura a largo plazo.

Orden recomendado:

1. Definir primero el objetivo real y el público real  
2. Construir primero la base de conocimiento  
3. Garantizar que el contenido sea verificable y mantenible  
4. Solo después usar la automatización para ganar eficiencia  

Si la base de conocimiento es débil, la automatización solo amplificará el ruido. En GEOFlow, **la calidad de la base de conocimiento debe ir primero**.

---

## 🚀 Inicio rápido

### Opción 1: Docker (desarrollo / demo)

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow
cp .env.example .env
vi .env

docker compose build
docker compose up -d
```

- Sitio: `http://localhost:18080` (puerto **`APP_PORT`**, por defecto `18080`)  
- Admin: `http://localhost:18080/geo_admin/login` (**`ADMIN_BASE_PATH`**, por defecto `geo_admin`)  

Con **`docker-compose.yml`**, el servicio **`init`** ejecuta la primera migración y `db:seed` cuando la base está lista (admin por defecto: véase más abajo).

### Suplemento: Docker (producción)

En producción use **`docker-compose.prod.yml`** con **Nginx + php-fpm**, no `php artisan serve`.

```bash
cp .env.prod.example .env.prod
vi .env.prod

docker compose --env-file .env.prod -f docker-compose.prod.yml build
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d postgres redis
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d init
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d app web queue scheduler reverb
```

- Frontend y admin entran por `web` (Nginx); PHP en `app` (php-fpm).  
- **Admin por defecto:** en producción **no** se ejecuta `db:seed` automáticamente; tras las migraciones ejecute una vez el comando indicado en **`docs/deployment/DEPLOYMENT.md`** (sección sobre administrador inicial y *seed*; el documento está en chino).  
- Más detalle: **`docs/deployment/DEPLOYMENT.md`**.

### Opción 2: PHP local

**Requisitos:** PHP **8.2+** (`pdo_pgsql`, `redis`, etc.), **PostgreSQL**, **Redis**, **Composer 2.x**.

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow
cp .env.example .env
composer install --no-interaction --prefer-dist
php artisan key:generate

php artisan migrate --force
php artisan db:seed --force
php artisan storage:link

php artisan serve --host=127.0.0.1 --port=8080
```

Otros terminales:

```bash
php artisan queue:work redis --queue=geoflow,default --sleep=1 --tries=1 --timeout=300
php artisan schedule:work
php artisan reverb:start
```

Admin: `http://127.0.0.1:8080/geo_admin/login`. **Producción:** Nginx + PHP-FPM, raíz **`public/`**.

---

## Credenciales por defecto (tras `db:seed`)

| Campo | Valor |
|-------|--------|
| Usuario | `admin` |
| Contraseña | `password` (**cámbiala en producción**) |

### Bloqueo por intentos fallidos y desbloqueo manual

- La cuenta de administrador se bloquea automáticamente (`status=locked`) tras **5** intentos fallidos consecutivos.
- Una cuenta bloqueada no puede iniciar sesión hasta que un administrador la desbloquee manualmente.
- Comando de desbloqueo:

```bash
php artisan geoflow:admin-unlock <username>
```

Ejemplo:

```bash
php artisan geoflow:admin-unlock admin
```

---

## Docker (resumen)

**Desarrollo** (`docker-compose.yml`): `postgres`, `redis`, `init`, `app` (`${APP_PORT:-18080}:8080`), `queue`, `scheduler`, `reverb` (`${REVERB_EXPOSE_PORT:-18081}:8080`). Variables de `docker/entrypoint.sh`: como en [README_en.md](README_en.md).

**Producción** (`docker-compose.prod.yml`): use `docker compose --env-file .env.prod -f docker-compose.prod.yml …` (véase el suplemento arriba y `docs/deployment/DEPLOYMENT.md`).

---

## Desarrollo y pruebas

```bash
composer test
./vendor/bin/pint
```

---

## 🌍 Otros idiomas

- [简体中文](README.md)
- [English](README_en.md)
- [日本語](README_ja.md)
- [Русский](README_ru.md)

---

## ⭐ Tendencia de estrellas

[![Star History Chart](https://api.star-history.com/svg?repos=yaojingang/GEOFlow&type=Date)](https://star-history.com/#yaojingang/GEOFlow&Date)

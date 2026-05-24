# GEOFlow

> Languages: [简体中文](../../README.md) | [English](README_en.md) | [日本語](README_ja.md) | [Español](README_es.md) | [Русский](README_ru.md) | [Português (BR)](README_pt_BR.md)

> GEOFlow es un sistema open source de ingeniería de contenidos GEO (Generative Engine Optimization) y distribución multi-sitio. Conecta bases de conocimiento, bibliotecas de materiales, prompts, tareas de generación con IA, revisión y publicación, analítica, paquetes de sitios destino GEOFlow Agent, canales WordPress REST y distribución remota de páginas estáticas para convertir información confiable en activos GEO publicables, trazables y distribuibles.

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)
[![License](https://img.shields.io/badge/License-Apache--2.0-blue.svg)](../../LICENSE)
[![GitHub stars](https://img.shields.io/github/stars/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/network/members)
[![GitHub issues](https://img.shields.io/github/issues/yaojingang/GEOFlow)](https://github.com/yaojingang/GEOFlow/issues)

GEOFlow se publica bajo la [Apache License 2.0](../../LICENSE). Puedes usarlo, copiarlo, modificarlo y distribuirlo, incluso con fines comerciales, siempre que conserves los avisos de copyright y licencia y cumplas los términos de patente, marcas y exención de garantías de Apache-2.0.

---

## ✨ Qué puedes hacer

| Característica | Descripción |
|----------------|-------------|
| 🤖 Generación multi-modelo | APIs estilo OpenAI y endpoints nativos de Gemini, modelos chat / embedding, adaptación de URL, failover inteligente, reintentos y estadísticas de uso |
| 🧠 RAG con base de conocimiento | Fragmentación por reglas, planificación semántica opcional con LLM, fallback estable, vectores con modelo embedding y recuperación de contexto durante la generación |
| 🗂 Materiales y prompts | Títulos, palabras clave, imágenes, autores, bases de conocimiento, prompts de cuerpo y prompts especiales |
| 📦 Automatización de tareas | Límites de generación, pool de borradores, revisión, cadencia de publicación, colas, reintentos, alcance de publicación y filtros por tarea |
| 📋 Revisión y artículos | Borradores, revisión, publicación, papelera, autores, categorías, SEO y origen de tarea en un solo flujo |
| 📡 Distribución multi-sitio | Canales GEOFlow Agent y WordPress REST, secretos, paquetes de sitio destino, modo estático, reglas rewrite, edición/eliminación remota, colas y logs |
| 🧾 Paquetes de sitio destino | PHP Agent por canal con home, páginas de artículo, assets estáticos, sitemap, `llms.txt` / mapas TXT y Schema |
| 📊 Analítica | Vista global, operación de sitio único, distribución multi-sitio, logs de acceso, top contenidos, crawlers de IA y tendencias |
| 🔍 Salida SEO y LLM-friendly | SEO, Open Graph, Schema, Markdown GFM, CSS independiente, sincronización de imágenes, sitemap y mapas TXT |
| 🎨 Front y temas | Temas, preview, cambio desde admin y sincronización remota de título, copyright, tema y categorías |
| 🌍 i18n del admin | Chino, inglés, japonés, español, ruso y portugués (Brasil), con módulos GEOFlow 2.0 cubiertos |
| 🔔 Avisos de versión | Consulta `version.json` de GitHub y avisa cuando hay una versión nueva |
| 🐳 Listo para desplegar | **Docker Compose**: Postgres (pgvector), Redis, app, cola, scheduler, Reverb y producción con Nginx/php-fpm |

---

## 🖼 Vista previa de la interfaz

<table>
  <tr>
    <td width="33%"><img src="../../docs/images/screenshots/dashboard-en.png" alt="GEOFlow admin dashboard preview" /><br /><sub>Admin Dashboard</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/analytics-en.png" alt="GEOFlow analytics preview" /><br /><sub>Analytics</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/tasks-en.png" alt="GEOFlow task management preview" /><br /><sub>Task Management</sub></td>
  </tr>
  <tr>
    <td width="33%"><img src="../../docs/images/screenshots/distribution-channel-en.png" alt="GEOFlow distribution channel preview" /><br /><sub>Distribution Channel</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/materials-en.png" alt="GEOFlow materials preview" /><br /><sub>Materials</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/ai-config-en.png" alt="GEOFlow AI model configuration preview" /><br /><sub>AI Model Configuration</sub></td>
  </tr>
  <tr>
    <td width="33%"><img src="../../docs/images/screenshots/site-settings-en.png" alt="GEOFlow site settings preview" /><br /><sub>Site Settings</sub></td>
    <td width="33%"></td>
    <td width="33%"></td>
  </tr>
</table>

Cubre el panel admin, analítica, tareas, distribución multi-sitio, materiales, configuración de modelos y ajustes del sitio.

---

## 🆕 Puntos clave de la nueva versión

GEOFlow 2.0 incluye estos cambios clave:

- **Panel como hub operativo**: conserva la guía de tres pasos y organiza entradas por operación de sitio único, distribución multi-sitio y skills complementarias.
- **Gemini y proveedores OpenAI-compatible**: la configuración de modelos cubre rutas OpenAI-style y Gemini nativo para chat / embedding.
- **Fragmentación semántica de conocimiento**: permite reglas estructuradas, modo automático o planificación semántica opcional con LLM; el LLM solo planifica límites y los chunks finales se reconstruyen desde el texto original.
- **Página de analítica independiente**: vista global, operación de contenido, salud de tareas/materiales, estado de distribución, logs de acceso y tendencias de crawlers de IA en `/admin/analytics`.
- **Distribución usable de extremo a extremo**: canales GEOFlow Agent y WordPress REST, secretos, pruebas de conexión, paquetes de sitio destino, modos estático/rewrite, sincronización de ajustes remotos, colas, logs, edición y eliminación remota.
- **Alcance de publicación explícito**: una tarea puede publicar en local y canales, solo en canales o solo en el sitio GEOFlow local; el modo local desactiva la selección de canales.
- **Sitios destino en modo estático**: la distribución regenera home remota, páginas de artículo, sitemap, mapas TXT, `llms.txt`, imágenes y CSS independiente.
- **Materiales y RAG más completos**: fragmentos, estado de vectorización, títulos, palabras clave, imágenes, autores y prompts forman la capa de entrada de las tareas.
- **Despliegue y seguridad mejorados**: Docker de producción usa Nginx + PHP-FPM, el seeder no sobrescribe admins existentes y los mirrors Docker/Composer son configurables.
- **Cobertura i18n para los módulos actuales**: los módulos GEOFlow 2.0 ya no dependen de claves sin traducir ni fallback en inglés.

---

## 🏗 Estructura de ejecución

```
Panel admin
  ↓
Configuración IA / materiales / prompts / tareas
  ↓
Scheduler / cola / worker ejecuta la IA
  ↓
Borrador / revisión / publicación
  ↓
Artículos locales y páginas SEO
  ↓
Cola de distribución / Agent del sitio destino
  ↓
Home remota, artículos, sitemap, mapas TXT y llms.txt
```

---

## 🧱 Arquitectura del sistema

| Capa | Descripción |
|------|-------------|
| Web / Admin | **Laravel**: rutas, controladores, sitio de artículos, **Blade** admin, analítica, distribución, materiales y tareas |
| API / Agent | APIs locales y PHP Agent de sitios destino para health check, recibir/actualizar/eliminar artículos, sincronizar ajustes y generar estáticos |
| Scheduler / cola / Reverb | **Scheduler**, **`queue:work` / Horizon** para generación y distribución, **Reverb** si aplica |
| Dominio y Jobs | `app/Services`, `app/Jobs`, `app/Http/Controllers` para IA, RAG, publicación, distribución y análisis de logs |
| Persistencia | **PostgreSQL** (recomendado **pgvector**) + **Redis** + JSON/archivos estáticos en sitios destino |

Flujo principal: configurar modelos y prompts → preparar conocimiento, títulos, palabras clave, imágenes y autores → crear tareas y encolar → workers generan contenido → borrador / revisión / publicación → páginas SEO locales → distribución a canales seleccionados → analítica de producción, distribución, acceso y crawlers de IA.

---

## ⚡ Inicio rápido desde el admin

1. **Configurar API**: añade al menos un modelo chat disponible; si necesitas RAG, añade un modelo embedding y elige una estrategia de fragmentación.
2. **Configurar materiales**: prepara base de conocimiento, títulos, palabras clave, imágenes y autores con información real y verificable.
3. **Crear tarea**: selecciona materiales, modelo, volumen, frecuencia y alcance de publicación; empieza con borrador o revisión antes de activar publicación automática y distribución multi-sitio.

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
- **Admin por defecto:** el servicio `init` de producción ejecuta `db:seed` después de las migraciones para crear la cuenta admin inicial; las ejecuciones repetidas no sobrescriben el usuario `admin` existente.
- Más detalle: **`../../docs/deployment/DEPLOYMENT.md`**.

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
php artisan queue:work redis --queue=geoflow,distribution,default --sleep=1 --tries=1 --timeout=300
php artisan schedule:work
php artisan reverb:start
```

Admin: `http://127.0.0.1:8080/geo_admin/login`. **Producción:** Nginx + PHP-FPM, raíz **`public/`**.

---

## Credenciales por defecto (tras `db:seed`)

| Campo | Valor |
|-------|--------|
| Usuario | `GEOFLOW_ADMIN_USERNAME`, por defecto `admin` |
| Contraseña | En desarrollo local es `password`; en producción define `GEOFLOW_ADMIN_PASSWORD`. Si está vacío y la cuenta aún no existe, el seeder genera una contraseña aleatoria de un solo uso en los logs de init / `db:seed`. |

El seeder solo crea la cuenta cuando el usuario objetivo no existe. Las ejecuciones repetidas nunca sobrescriben usuario, correo ni contraseña existentes.

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

**Producción** (`docker-compose.prod.yml`): use `docker compose --env-file .env.prod -f docker-compose.prod.yml …` (véase el suplemento arriba y `../../docs/deployment/DEPLOYMENT.md`).

---

## Desarrollo y pruebas

```bash
composer test
./vendor/bin/pint
```

---

## 🌍 Otros idiomas

- [简体中文](../../README.md)
- [English](README_en.md)
- [日本語](README_ja.md)
- [Русский](README_ru.md)

---

## 📄 Licencia

GEOFlow está licenciado bajo la [Apache License 2.0](../../LICENSE). Permite uso personal y comercial, modificación, redistribución y despliegue privado, siempre que se respeten los avisos de licencia, copyright, cambios, términos de patente y exenciones de garantía.

---

## ⭐ Tendencia de estrellas

[![Star History Chart](https://api.star-history.com/svg?repos=yaojingang/GEOFlow&type=Date)](https://star-history.com/#yaojingang/GEOFlow&Date)

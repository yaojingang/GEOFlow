# FAQ

## 1. What is the default admin URL and account?

- Admin URL: `/geo_admin/`
- Default admin username: `admin`
- Default admin password: `admin888`

After first login, you should change both the admin password and `APP_SECRET_KEY`.

## 2. Is Docker required?

No.

You can:

- use Docker Compose for `web + postgres + scheduler + worker`
- or run PHP and PostgreSQL locally

If the goal is to get started quickly and predictably, Docker is the safer option.

## 3. Is PostgreSQL required at runtime?

Yes.

The current public runtime database is PostgreSQL.  
The repository does not include production databases or sample business data.

## 4. Why are image libraries, knowledge files, and article data not included?

Because those are runtime or business assets, such as:

- image-library contents
- raw knowledge-base files
- generated articles
- logs and backups

The public repository only includes source code and configuration templates.

## 5. How do I connect an AI model?

Go to:

`AI Configuration Center -> AI Model Management`

Then fill in:

- API URL
- Model ID
- Bearer Token

The system supports OpenAI-style interfaces and already handles several versioned provider URL patterns.

## 6. What is the content-generation pipeline?

The core flow is:

1. Configure models, prompts, and material libraries
2. Create a task
3. Let the scheduler enqueue it
4. Let the worker execute generation
5. Route content through draft / review / publish
6. Deliver the result to the frontend

## 7. Is there a CLI or companion skill support?

Yes.

- CLI docs are available in the project documentation
- Companion skill repository: [yaojingang/yao-geo-skills](https://github.com/yaojingang/yao-geo-skills)
- Public skills currently include:
  - `geoflow-cli-ops`
  - `geoflow-template`

## 8. When is GEOFlow a good fit?

It is a good fit for:

- independent GEO websites
- GEO sub-channels inside official sites
- GEO source sites
- internal GEO content backends
- multi-site or multi-channel content operations

It is not a good fit if the goal is mass-producing low-value pages.

## 9. Why is the knowledge base treated as a priority?

Because GEOFlow is only as trustworthy as the knowledge assets behind it.  
If the knowledge layer is weak, automation only scales noise.

## 10. Which pages should I read first?

Recommended order:

1. [Getting Started](Getting-Started.md)
2. [What Is GEOFlow](What-Is-GEOFlow.md)
3. [GEOFlow Methodology](GEOFlow-Methodology.md)
4. [Principles and Content Boundaries](Principles-and-Content-Boundaries.md)
5. [Use Cases](Use-Cases.md)

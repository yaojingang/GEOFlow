# Deployment Checklist

This page is not the full deployment manual. It is the practical checklist to run before and after going live.

## 1. Base environment

Confirm these conditions first:

- PHP version matches current public requirements
- PostgreSQL is reachable
- core extensions such as `curl` and `pdo_pgsql` are enabled
- Web, Scheduler, and Worker are all wired correctly
- server timezone is confirmed

For Docker-based deployment, also confirm:

- `docker compose` works normally
- container networking is healthy
- `web / postgres / scheduler / worker` are all running

## 2. Environment variables and security items

Before production, check at least:

- `APP_SECRET_KEY` has been replaced with a long random value
- the default admin password has been changed
- `SITE_URL` matches the real domain
- ports, reverse proxy, and HTTPS are configured correctly
- no real API keys are exposed in the repository or public pages

## 3. Database and runtime state

Confirm:

- database tables are initialized
- admin login works
- model, task, and material pages can read and write correctly
- Scheduler can enqueue jobs
- Worker can execute jobs
- logs do not show repeated runtime errors

## 4. AI model and generation pipeline

Before going live, verify at least one full generation chain:

1. model configuration saves successfully
2. title generation works
3. body generation works
4. content lands in draft
5. review / publish workflow works

If you use reasoning models or smart failover, also verify:

- timeout rules match the model response profile
- fallback to secondary models actually works

## 5. Frontend output

Check at least these page types:

- homepage
- category page
- article page
- archive page

Also verify:

- `title / description / keywords`
- Open Graph
- structured data
- article images and list summaries
- theme switching does not break the data contract

## 6. Content quality and knowledge assets

At the business level, verify:

- the knowledge base is real and reviewable
- prompts do not encourage false or empty content
- task goals are clear
- output has a review or spot-check process

The threshold is not “it generates.” The threshold is “it generates something worth trusting.”

## 7. Monitoring and rollback readiness

Before launch, prepare:

- how to inspect logs
- how to trace task failures
- how to pause tasks
- how to disable a problematic model
- how to switch back to the default frontend

If you use theme packages:

- keep the default frontend available
- keep preview routes available
- confirm that admin can switch back quickly

## 8. Recommended rollout order

The safest order is:

1. validate locally or in staging
2. run a small real-content validation cycle
3. then expose the production domain
4. only then increase task volume and automation scope

Do not start with:

- large concurrent task volume
- many providers at once
- multiple template switches at once
- large batches of unreviewed content

Stability should come before scale.

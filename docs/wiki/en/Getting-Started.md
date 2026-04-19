# Getting Started

This page is not a full deployment manual. It is the shortest stable path to getting GEOFlow running and proving the core workflow.

## Step 1: Define the goal first

Clarify three things:

- what kind of site or content system you want to build
- who the audience is
- which knowledge assets you want to build first

If this part is unclear, models, prompts, and templates will drift.

## Step 2: Get the system running

Docker is the recommended starting point.

The basic path is:

1. clone the repository
2. copy `.env.example` to `.env`
3. adjust port, site URL, and secret key
4. start `web + postgres + scheduler + worker`

The first goal is not perfect configuration. The first goal is to get a reachable frontend and admin.

## Step 3: Sign in to the admin

Admin path:

- `/geo_admin/`

Default credentials:

- username: `admin`
- password: `admin888`

Immediately after login:

- change the admin password
- change `APP_SECRET_KEY`

## Step 4: Configure one stable model first

Go to:

`AI Configuration Center -> AI Model Management`

Start with one stable, reasonably fast chat model.  
You do not need the most complex setup for the first validation cycle.

## Step 5: Prepare the minimum materials

At minimum, prepare:

- one title library
- one knowledge base
- one body-generation prompt
- one author
- one category

If you do not yet have a real knowledge base, do not rush into large-scale task creation.

## Step 6: Create the first task

Recommended minimum task setup:

- title library: valid titles available
- model: one stable chat model
- prompt: body-generation prompt
- category: a clear content category
- review mode: start with draft / review

The first task is for workflow validation, not for scale.

## Step 7: Verify one full content cycle

At minimum, confirm these five things:

1. the task is queued correctly
2. the worker executes correctly
3. the article lands in draft
4. the review page shows the generated content
5. the frontend renders the published article correctly

Once those five points work, expansion becomes much safer.

## Step 8: Optimize in the right order

Recommended order:

1. knowledge base
2. models and prompts
3. tasks and review
4. frontend themes and templates
5. CLI / Skill / API automation

Do not start by optimizing for:

- very complex themes
- heavy automation
- large task volume

First prove the real content workflow. That is where GEOFlow starts to matter.

## Recommended next reading

If your first article workflow is already working, continue with:

- [What Is GEOFlow](What-Is-GEOFlow.md)
- [GEOFlow Methodology](GEOFlow-Methodology.md)
- [Use Cases](Use-Cases.md)
- [Deployment Patterns by Scenario](Deployment-Patterns-by-Scenario.md)

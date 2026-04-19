# Deployment Patterns by Scenario

Different GEOFlow use cases call for different deployment priorities.

## Standalone GEO website

Recommended pattern:

- deploy the full frontend and admin stack
- manage templates, sections, SEO, and ad slots together
- focus on product, FAQ, case, and solution content

## GEO sub-channel of an existing website

Recommended pattern:

- run GEOFlow under a subdirectory or subdomain
- keep the main site untouched
- let the content channel evolve independently first

## Independent source site

Recommended pattern:

- prioritize the knowledge base and information architecture
- update at a controlled pace
- focus on depth and maintenance, not on raw volume

## Internal GEO content backend

Recommended pattern:

- make the frontend secondary
- focus on models, prompts, assets, scheduling, and review
- coordinate through CLI, Skills, and APIs when needed

## Multi-site / multi-channel deployment

Recommended pattern:

- use themes and template packages as delivery boundaries
- share prompt and material libraries where appropriate
- split strategy by site, section, or audience

## Automated source-management system

Recommended pattern:

- keep the knowledge base at the center
- treat automation as controlled assistance
- optimize for stable updates, not uncontrolled scale

In all scenarios, the best order is the same:

1. define the real goal and audience
2. build the knowledge base
3. configure models and prompts
4. build task and review workflows
5. expand templates and automation later

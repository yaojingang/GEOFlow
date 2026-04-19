# Model Setup Guide

This page explains the safest way to connect AI models to GEOFlow.

## 1. Where model setup lives

Admin path:

`AI Configuration Center -> AI Model Management`

This area handles:

- adding models
- editing models
- setting model type
- configuring API URL and model ID
- enabling or disabling models
- setting failover priority

## 2. What you need to fill in

In most cases you need at least:

- model name
- API URL
- model ID
- Bearer Token / API Key
- model type

The most common starting point is a `chat` model.

## 3. API URL rules

GEOFlow supports both:

- provider base URLs
- full endpoint URLs

It expands capability-specific paths automatically:

- chat defaults to `/v1/chat/completions`
- embedding defaults to `/v1/embeddings`

It also handles versioned base paths such as:

- Zhipu `/api/paas/v4`
- Volcengine Ark `/api/v3`

## 4. Model-selection advice

At the beginning, it is better to:

- connect one stable chat model first
- prove title generation and body generation first
- only then add more complex provider combinations

Do not start by optimizing for:

- too many models at once
- complicated provider combinations
- aggressive fallback orchestration

## 5. Reasoning-model considerations

Some reasoning-capable models stay silent for longer before returning output.  
That means:

- request timeouts should not be too aggressive
- low-speed abort settings should not be too strict
- title and body generation should share sane timeout defaults

A model can be very strong but still be a poor fit for every task.  
For example, title generation often benefits more from fast models than from the heaviest reasoning models.

## 6. Smart model failover

GEOFlow currently supports two task modes:

- `fixed`
- `smart_failover`

Where:

- `fixed` always uses the primary model
- `smart_failover` tries the next available chat model by priority when the primary one fails

Recommendation:

- stabilize the main model first
- then enable failover
- make priorities explicit

## 7. Minimum post-setup validation

You should verify at least:

1. the model can be saved
2. title generation works
3. body generation works
4. tasks can use the model
5. if failover is enabled, fallback actually works

## 8. Common issues

### 404 from the model endpoint

Common causes:

- wrong base URL
- provider does not actually use a `/v1`-style path
- wrong model ID

### Timeout

Common causes:

- reasoning model is too slow for the request settings
- timeout strategy is too short
- provider-side network instability

### Model saves successfully but tasks still fail

Common causes:

- wrong model type
- invalid token
- quota limits
- provider response shape mismatch

## 9. Guiding principle

In one sentence:

> Start with one stable model, validate the workflow, then add complexity.

The value of model integration is not how many providers exist in the UI, but how reliably the workflow runs.

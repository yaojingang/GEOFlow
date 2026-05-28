#!/usr/bin/env node

const baseUrl = process.env.GEO_GETNOTE_BASE_URL || "http://localhost:18080";
const token = process.env.GEO_API_TOKEN;
const content = process.argv.slice(2).join(" ").trim();

if (!token) {
  console.error("Missing GEO_API_TOKEN. Create one in /workspace/settings with getnote:generate scope.");
  process.exit(1);
}

if (!content) {
  console.error("Usage: GEO_API_TOKEN=geo_xxx node examples/getnote-api-client.mjs \"article text or URL\"");
  process.exit(1);
}

const response = await fetch(`${baseUrl.replace(/\/$/, "")}/api/v1/getnote/generate`, {
  method: "POST",
  headers: {
    Authorization: `Bearer ${token}`,
    "Content-Type": "application/json",
  },
  body: JSON.stringify({
    content,
    context: "文章转笔记",
  }),
});

if (!response.ok) {
  throw new Error(`GetNote API failed (${response.status}): ${await response.text()}`);
}

const { data, markdown } = await response.json();
console.error(`Generated: ${data.title} (${data.source}/${data.model})`);
console.log(markdown);

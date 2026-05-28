# GetNote API

GetNote turns articles, URLs, social links, and supported files into a Markdown note. The API uses the same generation chain as `/getnote`: NotebookLM private API first, GPT relay fallback, rules fallback last.

## Endpoint

Local:

```text
POST http://localhost:18080/api/v1/getnote/generate
```

Production:

```text
POST https://geo.youngtuo.win/api/v1/getnote/generate
```

OpenAPI:

```text
GET https://geo.youngtuo.win/api/v1/getnote/openapi.json
```

Create a Workspace API Token at `/workspace/settings` and include the `getnote:generate` scope.

```http
Authorization: Bearer geo_xxx
```

## JSON Request

```bash
curl -sS -X POST http://localhost:18080/api/v1/getnote/generate \
  -H "Authorization: Bearer $GEO_API_TOKEN" \
  -H "Content-Type: application/json" \
  --data-binary @- <<'JSON'
{
  "content": "https://www.youtube.com/watch?v=VIDEO_ID",
  "context": "文章转笔记"
}
JSON
```

## File Upload

```bash
curl -sS -X POST http://localhost:18080/api/v1/getnote/generate \
  -H "Authorization: Bearer $GEO_API_TOKEN" \
  -F "file=@./source.pdf" \
  -F "context=文章转笔记"
```

Supported text extraction includes PDF, DOCX, TXT, MD, HTML, JSON, CSV, and common webpage/social URLs. Images, audio, and video files are accepted as source metadata, but OCR/transcription gaps may be reported in the note.

## Response

```json
{
  "data": {
    "source": "notebooklm",
    "model": "notebooklm-private-api",
    "title": "Note title",
    "summary": "Structured note body",
    "noteType": "source-note",
    "tags": [],
    "knowledgeBases": [],
    "actions": [],
    "apiPreview": "",
    "recallQueries": [],
    "safetyChecks": []
  },
  "markdown": "# Note title\n\nStructured note body\n\n来源: NotebookLM"
}
```

`data.source` can be `notebooklm`, `model`, or `rules`.

## JavaScript

```js
const response = await fetch("http://localhost:18080/api/v1/getnote/generate", {
  method: "POST",
  headers: {
    Authorization: `Bearer ${process.env.GEO_API_TOKEN}`,
    "Content-Type": "application/json",
  },
  body: JSON.stringify({
    content: "把这段文字转成笔记。",
    context: "文章转笔记",
  }),
});

if (!response.ok) {
  throw new Error(await response.text());
}

const { data, markdown } = await response.json();
console.log(data.title);
console.log(markdown);
```

## Runnable Example

```bash
GEO_API_TOKEN=geo_xxx \
node examples/getnote-api-client.mjs "https://www.youtube.com/watch?v=VIDEO_ID" > note.md
```

Override the server URL when calling production or another local port:

```bash
GEO_GETNOTE_BASE_URL=https://geo.youngtuo.win \
GEO_API_TOKEN=geo_xxx \
node examples/getnote-api-client.mjs "把这段文字转成笔记。" > note.md
```

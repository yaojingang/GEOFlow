import { siteUrl } from "@/data/workspace";

export function GET() {
  return Response.json({
    openapi: "3.1.0",
    info: {
      title: "GetNote API",
      version: "1.0.0",
      description: "Turn articles, URLs, social links, and supported files into Markdown notes.",
    },
    servers: [
      {
        url: siteUrl,
        description: "Production",
      },
      {
        url: "http://localhost:18080",
        description: "Local Docker",
      },
    ],
    paths: {
      "/api/v1/getnote/generate": {
        post: {
          operationId: "generateGetNote",
          summary: "Generate a Markdown note from text, URL, or file",
          description:
            "Uses NotebookLM private API first when configured, GPT relay fallback, and rules fallback last.",
          security: [{ bearerAuth: [] }],
          requestBody: {
            required: true,
            content: {
              "application/json": {
                schema: {
                  type: "object",
                  required: ["content"],
                  properties: {
                    content: {
                      type: "string",
                      minLength: 1,
                      maxLength: 20000,
                      description: "Article text, webpage URL, social URL, or other text content.",
                    },
                    context: {
                      type: "string",
                      maxLength: 800,
                      default: "文章转笔记",
                    },
                  },
                },
              },
              "multipart/form-data": {
                schema: {
                  type: "object",
                  properties: {
                    file: {
                      type: "string",
                      format: "binary",
                      description: "PDF, DOCX, TXT, MD, HTML, JSON, CSV, image, audio, or video file up to 12MB.",
                    },
                    content: {
                      type: "string",
                      maxLength: 20000,
                      description: "Optional supplemental text.",
                    },
                    context: {
                      type: "string",
                      maxLength: 800,
                      default: "文章转笔记",
                    },
                  },
                  anyOf: [{ required: ["file"] }, { required: ["content"] }],
                },
              },
            },
          },
          responses: {
            "200": {
              description: "Generated note",
              content: {
                "application/json": {
                  schema: {
                    type: "object",
                    required: ["data", "markdown"],
                    properties: {
                      data: { $ref: "#/components/schemas/GetNoteDraft" },
                      markdown: {
                        type: "string",
                        description: "Ready-to-save Markdown, starting with a level-1 title.",
                      },
                    },
                  },
                },
              },
            },
            "401": {
              description: "Missing API token",
            },
            "403": {
              description: "Token does not include getnote:generate scope",
            },
            "422": {
              description: "Invalid input",
            },
          },
        },
      },
    },
    components: {
      securitySchemes: {
        bearerAuth: {
          type: "http",
          scheme: "bearer",
          description: "Workspace API Token with getnote:generate scope.",
        },
      },
      schemas: {
        GetNoteDraft: {
          type: "object",
          required: [
            "source",
            "model",
            "title",
            "summary",
            "noteType",
            "tags",
            "knowledgeBases",
            "actions",
            "apiPreview",
            "recallQueries",
            "safetyChecks",
          ],
          properties: {
            source: {
              type: "string",
              enum: ["notebooklm", "model", "rules"],
            },
            model: {
              type: "string",
            },
            title: {
              type: "string",
            },
            summary: {
              type: "string",
            },
            noteType: {
              type: "string",
            },
            tags: {
              type: "array",
              items: { type: "string" },
            },
            knowledgeBases: {
              type: "array",
              items: { type: "string" },
            },
            actions: {
              type: "array",
              items: { type: "string" },
            },
            apiPreview: {
              type: "string",
            },
            recallQueries: {
              type: "array",
              items: { type: "string" },
            },
            safetyChecks: {
              type: "array",
              items: { type: "string" },
            },
          },
        },
      },
    },
  });
}

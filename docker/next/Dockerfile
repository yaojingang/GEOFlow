FROM node:22-bookworm-slim AS deps
WORKDIR /app
RUN apt-get update -y && apt-get install -y --no-install-recommends openssl ca-certificates \
  && rm -rf /var/lib/apt/lists/*
COPY package.json package-lock.json* ./
RUN npm install

FROM node:22-bookworm-slim AS builder
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1
RUN apt-get update -y && apt-get install -y --no-install-recommends openssl ca-certificates \
  && rm -rf /var/lib/apt/lists/*
COPY --from=deps /app/node_modules ./node_modules
COPY . .
RUN npx prisma generate
RUN npm run build

FROM node:22-bookworm-slim AS runner
WORKDIR /app
ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
RUN apt-get update -y && apt-get install -y --no-install-recommends openssl ca-certificates python3 python3-venv \
  && python3 -m venv /opt/notebooklm \
  && /opt/notebooklm/bin/pip install --no-cache-dir notebooklm-py \
  && rm -rf /var/lib/apt/lists/*
RUN groupadd --system --gid 1001 nodejs && useradd --system --uid 1001 nextjs
COPY --from=builder /app/public ./public
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static
COPY --from=builder /app/package.json ./package.json
COPY --from=builder /app/scripts/notebooklm_getnote.py ./scripts/notebooklm_getnote.py
COPY --from=builder /app/node_modules/.prisma ./node_modules/.prisma
COPY --from=builder /app/node_modules/@emnapi ./node_modules/@emnapi
COPY --from=builder /app/node_modules/@napi-rs ./node_modules/@napi-rs
COPY --from=builder /app/node_modules/pdf-parse/dist/pdf-parse/esm/pdf.worker.mjs ./.next/server/chunks/pdf.worker.mjs
USER nextjs
EXPOSE 3000
ENV PORT=3000
CMD ["node", "server.js"]

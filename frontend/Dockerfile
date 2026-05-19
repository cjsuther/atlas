# =========================================================
# ATLAS v2 — Frontend (Vue 3 + Vite)
# Multi-stage: node build → nginx static
# =========================================================

# ---- Stage 1: build ----
FROM node:20-alpine AS build
WORKDIR /app

COPY package.json ./
RUN npm install --no-audit --no-fund

COPY index.html ./
COPY vite.config.js ./
COPY public ./public
COPY src ./src

RUN npm run build

# ---- Stage 2: nginx ----
FROM nginx:1.27-alpine
RUN rm /etc/nginx/conf.d/default.conf
COPY nginx.conf /etc/nginx/conf.d/atlas-front.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]

# DisplaFruit — dashboard de producción (servicio autocontenido Node).
FROM node:22-alpine

WORKDIR /app

COPY package.json ./
RUN npm install --omit=dev --no-audit --no-fund

COPY server.js ./
COPY operarios.js ./
COPY public ./public

EXPOSE 8080

USER node
CMD ["node", "server.js"]

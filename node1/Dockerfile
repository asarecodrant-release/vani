FROM node:20

WORKDIR /app

COPY node1/package*.json ./
RUN npm install

COPY node1/ .

RUN npx playwright install chromium --with-deps

CMD ["npm", "run", "render:server"]

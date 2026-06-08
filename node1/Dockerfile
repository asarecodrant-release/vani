FROM node:20

WORKDIR /app

COPY package*.json ./
RUN npm install

COPY . .

RUN npx playwright install chromium --with-deps

CMD ["npm", "run", "render:server"]

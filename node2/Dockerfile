FROM node:20

WORKDIR /app

COPY package*.json ./
RUN npm install

COPY . .

ENV PORT=3001

CMD ["npm", "run", "queue:worker"]
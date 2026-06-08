FROM node:20

WORKDIR /app

COPY node2/package*.json ./
RUN npm install

COPY node2/ .

CMD ["npm", "run", "queue:worker"]

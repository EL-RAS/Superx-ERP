FROM node:22-alpine

WORKDIR /app
COPY package*.json ./

RUN npm install
COPY . .

ARG NEXT_PUBLIC_API_URL
ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL

# Base domain of the central app. Used to recognise a tenant host
# ({subdomain}.{base}) so the login page performs tenant-domain login instead
# of the owner-only central login. Inlined at build time — rebuild the image
# after changing it.
ARG NEXT_PUBLIC_SUPERX_BASE_DOMAIN
ENV NEXT_PUBLIC_SUPERX_BASE_DOMAIN=$NEXT_PUBLIC_SUPERX_BASE_DOMAIN

RUN npm run build

EXPOSE 3000
CMD ["npm", "start"]
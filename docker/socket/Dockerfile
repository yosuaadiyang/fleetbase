FROM socketcluster/socketcluster@sha256:3cd5c2dde94eab1d4b4dde7d35a6eb8e5a109e7cb491c6f3964111144557b085
COPY docker/socket/server.js docker/socket/security.js /usr/src/
WORKDIR /usr/src
CMD ["node", "server.js"]

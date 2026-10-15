'use strict';
const http = require('http');
const socketClusterServer = require('socketcluster-server');
const {createAuthorizer, installSecurity} = require('./security');
const secret = process.env.CONTRUST_SOCKET_PUBLISHER_KEY;
const options = JSON.parse(process.env.SOCKETCLUSTER_OPTIONS || '{}');
const server = http.createServer((req, res) => {
  res.writeHead(req.method === 'GET' && req.url === '/health-check' ? 200 : 404, {'Content-Type': 'text/plain'});
  res.end(req.url === '/health-check' ? 'OK' : 'Not found');
});
const sockets = socketClusterServer.attach(server, {...options,
  socketChannelLimit: 100, maxPayload: 65536, allowClientPublish: true, perMessageDeflate: false});
installSecurity(sockets, {secret, authorize: createAuthorizer({secret,
  url: process.env.CONTRUST_SOCKET_AUTH_URL || 'http://application:8000/int/v1/contrust/socket-authorize'})});
(async () => { for await (const _ of sockets.listener('error')) console.error('Socket transport error'); })();
server.listen(Number(process.env.SOCKETCLUSTER_PORT || 8000));

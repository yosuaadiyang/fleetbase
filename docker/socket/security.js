'use strict';
const http = require('http');
const crypto = require('crypto');

function equalSecret(actual, expected) {
  return typeof actual === 'string' && typeof expected === 'string' && /^[a-f0-9]{64}$/.test(actual)
    && actual.length === expected.length && crypto.timingSafeEqual(Buffer.from(actual), Buffer.from(expected));
}
function cookieTicket(header) {
  if (typeof header !== 'string' || header.length > 16384) return null;
  const matches = [...header.matchAll(/(?:^|;\s*)contrust_socket=([a-f0-9]{64})(?=;|$)/g)];
  return matches.length === 1 ? matches[0][1] : null;
}
function createAuthorizer({secret, url, request = http.request}) {
  const endpoint = new URL(url);
  if (endpoint.protocol !== 'http:' || !['application', '127.0.0.1'].includes(endpoint.hostname)) throw new Error('Private authorization endpoint required');
  let pending = 0;
  return async function authorize(ticket, channel) {
    if (!/^[a-f0-9]{64}$/.test(ticket || '') || typeof channel !== 'string' || channel.length > 150 || pending >= 32) return false;
    pending++;
    try {
      return await new Promise(resolve => {
        const body = JSON.stringify({ticket, channel});
        let finished = false;
        const finish = value => { if (!finished) { finished = true; resolve(value); } };
        const req = request(endpoint, {method: 'POST', headers: {'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body),
          'X-Contrust-Socket-Publisher': secret}}, res => {
          let data = '';
          res.on('data', chunk => { data += chunk; if (data.length > 1024) { finish(false); res.destroy(); } });
          res.on('error', () => finish(false));
          res.on('end', () => { try { finish(res.statusCode === 200 && JSON.parse(data).allowed === true); } catch { finish(false); } });
        });
        req.setTimeout(2000, () => { finish(false); req.destroy(); });
        req.on('error', () => finish(false));
        req.end(body);
      });
    } catch { return false; } finally { pending--; }
  };
}
function installSecurity(server, {secret, authorize}) {
  if (!/^[a-f0-9]{64}$/.test(secret || '')) throw new Error('Publisher secret required');
  const publisher = socket => equalSecret(socket.request.headers['x-contrust-socket-publisher'], secret);
  function denied() { const error = new Error('Channel access denied'); error.name = 'ForbiddenError'; return error; }
  server.setMiddleware(server.MIDDLEWARE_INBOUND, async stream => {
    for await (const action of stream) {
      try {
        if (action.type === action.SUBSCRIBE) {
          const ticket = cookieTicket(action.socket.request.headers.cookie);
          if (ticket && await authorize(ticket, action.channel)) action.allow();
          else { action.block(denied()); if (!ticket) setTimeout(() => action.socket.disconnect(4001), 20); }
        } else if (action.type === action.PUBLISH_IN) {
          if (publisher(action.socket) && typeof action.channel === 'string' && action.channel.length <= 150) action.allow();
          else action.block(denied());
        } else if ([action.TRANSMIT, action.INVOKE].includes(action.type)) action.block(denied());
        else action.allow(); // Protocol handshake/message framing, not application data.
      } catch { if (!action.outcome) action.block(denied()); }
    }
  });
  server.setMiddleware(server.MIDDLEWARE_OUTBOUND, async stream => {
    for await (const action of stream) {
      try {
        // Check every delivery: revoked tokens and reassigned jobs cannot retain a subscription.
        if (action.type !== action.PUBLISH_OUT || await authorize(cookieTicket(action.socket.request.headers.cookie), action.channel)) action.allow();
        else { action.block(denied()); action.socket.disconnect(4001); }
      } catch { if (!action.outcome) action.block(denied()); }
    }
  });
}
module.exports = {equalSecret, cookieTicket, createAuthorizer, installSecurity};

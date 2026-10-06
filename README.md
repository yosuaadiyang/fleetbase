<p align="center">
  <img src="console/public/images/contrust-logo.png" alt="Contrust" width="400" />
</p>

<p align="center"><strong>Logistics and fleet operations platform</strong></p>

---

## What is Contrust?

Contrust runs dispatch, fleet management, live tracking, commerce and finance for logistics
operations in one platform, with a REST API, webhooks and real-time updates. It is
self-hosted: you run it on your own server and keep full control of your data.

## Modules

| Module | What it does |
|--------|--------------|
| **Fleet-Ops** | Fleet management and dispatch: orders, drivers, vehicles, live tracking, route optimization, configurable workflows and maintenance. |
| **Storefront** | Headless commerce for on-demand businesses, with multi-vendor marketplaces and built-in delivery. |
| **Ledger** | Invoicing, payments, wallets and accounting. |
| **Customer Portal** | A self-service workspace for customers to place and track orders, get quotes, pay, and view invoices. |
| **IAM** | Users, groups, roles, policies and two-factor authentication. |
| **Developers** | API keys, webhooks, socket and system events, and request logs, with separate test and live environments. |

## Features

| Feature | Description |
|---------|-------------|
| **Developer friendly** | REST API, WebSockets and webhooks for integrating external systems. |
| **Configurable workflows** | Order types with their own activity flows, custom fields and automation. |
| **Real-time operations** | Track drivers, vehicles and orders live, with geofences and service zones. |
| **Telematics** | Connect GPS devices and sensors for live feedback from the field. |
| **Dashboards** | Custom dashboards and widgets for visibility into operations. |
| **Internationalized** | The console is translated into 16 languages. |

## Getting started

**Production (a VPS with your own domains and HTTPS):** follow [DEPLOYING.md](DEPLOYING.md).

**Local development:** with Docker Compose v2, Git and at least 4 GB of memory for Docker:

```bash
bash scripts/docker-install.sh --non-interactive
```

Then open http://localhost:4200 and create your administrator account and organization.
The API is served at http://localhost:8000. See [scripts/README.md](scripts/README.md)
for the installer's options.

## License

Contrust is a modified version of [Fleetbase](https://github.com/fleetbase/fleetbase), and
is licensed under the [GNU Affero General Public License v3.0](LICENSE.md) or later.

- Copyright © Fleetbase Pte. Ltd. and contributors, for Fleetbase.
- Modifications © Contrust.

If you make this software available to users over a network, the AGPL-3.0 requires you to
offer those users its complete source code, including your modifications. The console's
**Legal** notice links to it (see "Branding and license" in [DEPLOYING.md](DEPLOYING.md)).

This program is distributed without any warranty; see the license for details.

# Vendored SheetJS Community Edition

`xlsx-0.20.3.tgz` comes from the publisher's authoritative release URL:
https://cdn.sheetjs.com/xlsx-0.20.3/xlsx-0.20.3.tgz

SHA-256: `8dc73fc3b00203e72d176e85b50938627c7b086e607c682e8d3c22c02bb99fe8`.

The tarball is retained unchanged with its upstream license and notices. The workspace override applies it to Fleet-Ops imports as well as regression tests. The npm `xlsx` release is older; using the publisher archive follows the installation and vendoring instructions:
https://docs.sheetjs.com/docs/getting-started/installation/nodejs/

Any replacement must be verified against the publisher, pinned in the lockfile, and pass import/export and production-build regressions. Do not replace this with the old npm release merely to simplify installation.

"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const crypto = require("node:crypto");
const { pathToFileURL } = require("node:url");
const { createRequire } = require("node:module");
const root = path.resolve(__dirname, "../../console");
const load = createRequire(path.join(root, "package.json"));
const manifest = JSON.parse(
  fs.readFileSync(path.join(root, "security-backports.json"), "utf8"),
);
let passed = 0;
function check(callback) {
  callback();
  passed++;
}
for (const patch of manifest.backports) {
  check(() => {
    let directory = path.dirname(load.resolve(patch.package));
    let metadata;
    while (directory !== path.dirname(directory)) {
      const file = path.join(directory, "package.json");
      if (fs.existsSync(file)) {
        const parsed = JSON.parse(fs.readFileSync(file, "utf8"));
        if (parsed.name === patch.package) {
          metadata = parsed;
          break;
        }
      }
      directory = path.dirname(directory);
    }
    assert.equal(metadata?.version, patch.version);
    const bytes = fs.readFileSync(path.join(root, patch.patch));
    assert.equal(
      crypto.createHash("sha256").update(bytes).digest("hex"),
      patch.sha256,
    );
  });
}
const braces = load("braces");
const { sprintf } = load("sprintf-js");
const tmp = load("tmp");
const deepPattern = "{".repeat(2000) + "a,b" + "}".repeat(2000);
for (const operation of [
  braces,
  braces.compile,
  braces.expand,
  braces.stringify,
  braces.parse,
]) {
  check(() =>
    assert.throws(() => operation(deepPattern), /safe traversal limits/),
  );
}
let ast = { type: "text", value: "synthetic" };
for (let i = 0; i < 500; i++) ast = { type: "root", nodes: [ast] };
for (const operation of [braces.compile, braces.expand, braces.stringify]) {
  check(() => assert.throws(() => operation(ast), /safe traversal limits/));
}
check(() =>
  assert.deepEqual(braces.expand("file{1..3}"), ["file1", "file2", "file3"]),
);
check(() => assert.equal(braces.compile("a{b,c}"), "a(b|c)"));
check(() =>
  assert.deepEqual(braces.expand("x/{a,{b,c}}"), ["x/a", "x/b", "x/c"]),
);
check(() => assert.throws(() => sprintf("%1000000000s", "x"), /safe limit/));
check(() => assert.throws(() => sprintf("%.1000000000s", "x"), /safe limit/));
check(() => assert.equal(sprintf("%04d %.2f", 3, 1.5), "0003 1.50"));
check(() =>
  assert.equal(sprintf("%(name)s", { name: "synthetic" }), "synthetic"),
);
const parent = fs.mkdtempSync(
  path.join(os.tmpdir(), "contrust-dependency-qa-"),
);
const allowed = path.join(parent, "allowed");
fs.mkdirSync(allowed);
try {
  for (const prefix of [
    { toString: () => "../outside-" },
    ["../outside-"],
    true,
    7,
  ]) {
    check(() => assert.throws(() => tmp.fileSync({ dir: allowed, prefix })));
  }
  check(() => {
    const file = tmp.fileSync({
      dir: allowed,
      prefix: "safe-",
      postfix: ".txt",
    });
    try {
      assert.equal(
        fs.realpathSync(path.dirname(file.name)),
        fs.realpathSync(allowed),
      );
    } finally {
      file.removeCallback();
    }
    assert.equal(fs.existsSync(file.name), false);
  });
  check(() => assert.deepEqual(fs.readdirSync(parent), ["allowed"]));
} finally {
  fs.rmSync(parent, { recursive: true, force: true });
}
async function workbookChecks() {
  const XLSX = load("xlsx");
  const engine = path.dirname(
    load.resolve("@fleetbase/fleetops-engine/package.json"),
  );
  const { default: readImportWorkbook } = await import(
    pathToFileURL(path.join(engine, "addon/utils/read-import-workbook.js")).href
  );
  check(() => {
    const rows = [
      ["name", "phone", "amount"],
      ["DEMO Pengiriman", "+620000000000", 10000],
      ["Gudang – Uji", "00123", 0],
    ];
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(
      workbook,
      XLSX.utils.aoa_to_sheet(rows),
      "Import",
    );
    const decoded = readImportWorkbook(
      XLSX.write(workbook, { type: "array", bookType: "xlsx" }),
    );
    assert.deepEqual(
      XLSX.utils.sheet_to_json(decoded.Sheets.Import, {
        header: 1,
        defval: "",
      }),
      rows,
    );
  });
  for (const name of ["__proto__", "constructor", "prototype"]) {
    check(() => {
      const workbook = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(
        workbook,
        XLSX.utils.aoa_to_sheet([["name"], ["synthetic"]]),
        name,
      );
      const bytes = XLSX.write(workbook, { type: "array", bookType: "xlsx" });
      assert.throws(
        () => readImportWorkbook(bytes),
        /unsupported worksheet name/,
      );
      assert.equal(Object.prototype.synthetic, undefined);
    });
  }
  console.log(
    JSON.stringify({
      dependency_security_checks: passed,
      failed: 0,
      backport_versions_intentionally_unchanged: true,
    }),
  );
}
workbookChecks().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

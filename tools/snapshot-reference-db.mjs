// Data migration, step 1: dump the reference system's database (PostgreSQL via Prisma) to one JSON snapshot.
// Step 2 is `php artisan db:seed` (or `php artisan scm:import-snapshot <file>`), which loads it into MySQL.
//
// Usage (reference database must be running):
//   node tools/snapshot-reference-db.mjs "<reference>/apps/api" [out.json]
//
// The same two steps move LIVE data from the old system to the new one: run this against production, then import.
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const refApi = process.argv[2];
if (!refApi) { console.error('pass the path to the reference apps/api folder'); process.exit(1); }
const out = process.argv[3] || path.join(ROOT, 'database/seed-data/snapshot.json');

const require = createRequire(path.join(path.resolve(refApi), 'package.json'));
require('dotenv').config({ path: path.join(path.resolve(refApi), '.env') });
require('dotenv').config({ path: path.join(path.resolve(refApi), '../../.env') });
const { PrismaClient, Prisma } = require('@prisma/client');
const prisma = new PrismaClient();

// Session / replay tables are never migrated.
const SKIP = new Set(['RefreshToken', 'IdempotencyKey']);

const tables = {};
let rows = 0;
for (const model of Prisma.dmmf.datamodel.models) {
  if (SKIP.has(model.name)) continue;
  const scalar = model.fields.filter((f) => f.kind !== 'object');
  const columns = Object.fromEntries(scalar.map((f) => [f.name, f.isList ? 'Json' : f.kind === 'enum' ? 'String' : f.type]));
  const delegate = prisma[model.name.charAt(0).toLowerCase() + model.name.slice(1)];
  const data = await delegate.findMany();
  tables[model.dbName || model.name] = {
    model: model.name,
    columns,
    rows: data.map((r) => Object.fromEntries(scalar.map((f) => {
      const v = r[f.name];
      if (v === null || v === undefined) return [f.name, null];
      if (v instanceof Date) return [f.name, v.toISOString()];
      if (typeof v === 'bigint') return [f.name, v.toString()];
      if (f.type === 'Decimal') return [f.name, v.toString()];
      return [f.name, v];
    }))),
  };
  rows += data.length;
}
await prisma.$disconnect();

// The committed demo snapshot never carries password hashes (the seeder sets SEED_PASSWORD on every user anyway).
// A LIVE migration needs them: write to another file, or set KEEP_PASSWORD_HASHES=1.
const isDemoFile = path.resolve(out) === path.join(ROOT, 'database/seed-data/snapshot.json');
if (isDemoFile && process.env.KEEP_PASSWORD_HASHES !== '1') {
  for (const t of Object.values(tables)) if (t.model === 'User') t.rows.forEach((r) => { r.passwordHash = '!'; });
  console.log('password hashes removed from the demo snapshot');
}

fs.mkdirSync(path.dirname(out), { recursive: true });
fs.writeFileSync(out, JSON.stringify({ takenAt: new Date().toISOString(), source: 'reference NestJS/PostgreSQL system', tables }));
console.log('snapshot: ' + Object.keys(tables).length + ' tables, ' + rows + ' rows -> ' + out + ' (' + Math.round(fs.statSync(out).size / 1024) + ' KB)');

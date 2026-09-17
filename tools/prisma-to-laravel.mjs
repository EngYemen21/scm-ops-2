// One-off generator: docs/reference/schema.prisma (the NestJS reference system) -> Laravel migrations + Eloquent models.
// Usage: node tools/prisma-to-laravel.mjs   (re-runnable; overwrites generated files only)
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const src = fs.readFileSync(path.join(ROOT, 'docs/reference/schema.prisma'), 'utf8');

// ---------- helpers (snake/camel identical to Illuminate\Support\Str) ----------
const snake = (s) => s.replace(/(.)(?=[A-Z])/g, '$1_').toLowerCase();
const camel = (s) => s.replace(/_([a-z0-9])/g, (_, c) => c.toUpperCase());
const TEXTY = /(note|notes|message|description|reason|details|comment|address|body|summary|instructions|justification|error|remarks|condition|resolution|cause|text)$/i;

// ---------- parse ----------
const enums = {};
const models = [];
const blockRe = /^(model|enum)\s+(\w+)\s*\{([\s\S]*?)^\}/gm;
let m;
while ((m = blockRe.exec(src))) {
  const [, kind, name, body] = m;
  const lines = body.split('\n').map((l) => l.replace(/\/\/.*$/, '').trim()).filter(Boolean);
  if (kind === 'enum') { enums[name] = lines; continue; }
  const model = { name, table: snake(name) + 's', fields: [], indexes: [], uniques: [], compositeId: null };
  for (const line of lines) {
    if (line.startsWith('@@')) {
      const cols = (line.match(/\[([^\]]*)\]/) || [null, ''])[1].split(',').map((c) => c.trim().replace(/\(.*\)/, '')).filter(Boolean);
      if (line.startsWith('@@map')) model.table = line.match(/"([^"]+)"/)[1];
      else if (line.startsWith('@@index')) model.indexes.push(cols);
      else if (line.startsWith('@@unique')) model.uniques.push(cols);
      else if (line.startsWith('@@id')) model.compositeId = cols;
      continue;
    }
    const fm = line.match(/^(\w+)\s+(\w+)(\[\])?(\?)?\s*(.*)$/);
    if (!fm) continue;
    const [, fname, ftype, list, opt, rest] = fm;
    const f = { name: fname, type: ftype, list: !!list, optional: !!opt, attrs: rest, col: snake(fname) };
    if (camel(f.col) !== fname) throw new Error('snake/camel round trip fails for ' + name + '.' + fname + ' -> ' + f.col);
    f.isId = /@id\b/.test(rest);
    f.unique = /@unique\b/.test(rest);
    const dm = rest.match(/@default\(((?:[^()]|\([^()]*\))*)\)/);
    f.default = dm ? dm[1].trim() : null;
    const dec = rest.match(/@db\.Decimal\((\d+),\s*(\d+)\)/);
    f.decimal = dec ? [+dec[1], +dec[2]] : null;
    const rel = rest.match(/@relation\(([^)]*)\)/);
    if (rel) {
      const r = rel[1];
      const list1 = (re) => (r.match(re) || [null, ''])[1].split(',').map((x) => x.trim()).filter(Boolean);
      f.rel = {
        name: (r.match(/^"([^"]+)"/) || r.match(/name:\s*"([^"]+)"/) || [null, null])[1],
        fields: list1(/fields:\s*\[([^\]]*)\]/),
        references: list1(/references:\s*\[([^\]]*)\]/),
        onDelete: (r.match(/onDelete:\s*(\w+)/) || [null, null])[1],
      };
    }
    model.fields.push(f);
  }
  models.push(model);
}
const byName = Object.fromEntries(models.map((x) => [x.name, x]));
const isModel = (t) => !!byName[t];
const isEnum = (t) => !!enums[t];
const field = (model, n) => model.fields.find((f) => f.name === n);

// ---------- which string columns hold ULIDs (ids + FKs pointing at ids), to a fixpoint ----------
const ulid = new Set();
for (const mo of models) for (const f of mo.fields) if (f.type === 'String' && f.isId && f.default === 'cuid()') ulid.add(mo.name + '.' + f.name);
for (let changed = true; changed;) {
  changed = false;
  for (const mo of models) {
    for (const f of mo.fields) {
      if (!f.rel || !f.rel.fields.length) continue;
      f.rel.fields.forEach((fk, i) => {
        const key = mo.name + '.' + fk;
        if (!ulid.has(key) && ulid.has(f.type + '.' + f.rel.references[i])) { ulid.add(key); changed = true; }
      });
    }
  }
}

// ---------- migrations ----------
const q = (s) => "'" + s + "'";
const idxName = (table, cols, suffix) => {
  const n = table + '_' + cols.join('_') + '_' + suffix;
  return n.length <= 60 ? n : table.slice(0, 30) + '_' + crypto.createHash('md5').update(n).digest('hex').slice(0, 10) + '_' + suffix;
};
const phpDefault = (f) => {
  const d = f.default;
  if (d == null || d === 'cuid()' || d === 'now()' || d === '[]' || d === 'autoincrement()') return null;
  if (d === 'true' || d === 'false') return d;
  if (/^-?\d+(\.\d+)?$/.test(d)) return d;
  if (/^".*"$/.test(d)) return q(d.slice(1, -1).replace(/'/g, "\\'"));
  return q(d); // enum literal
};
function columnLine(mo, f) {
  const c = q(f.col);
  let t;
  if (f.list) t = '$table->json(' + c + ')';
  else if (ulid.has(mo.name + '.' + f.name)) t = '$table->ulid(' + c + ')';
  else if (f.type === 'String') t = (TEXTY.test(f.name) && f.default == null && !f.unique && !f.isId) ? '$table->text(' + c + ')' : '$table->string(' + c + ')';
  else if (f.type === 'Int') t = '$table->integer(' + c + ')';
  else if (f.type === 'BigInt') t = '$table->bigInteger(' + c + ')';
  else if (f.type === 'Float') t = '$table->double(' + c + ')';
  else if (f.type === 'Decimal') t = '$table->decimal(' + c + ', ' + (f.decimal || [18, 4]).join(', ') + ')';
  else if (f.type === 'Boolean') t = '$table->boolean(' + c + ')';
  else if (f.type === 'DateTime') t = '$table->dateTime(' + c + ', 3)';
  else if (f.type === 'Json') t = '$table->json(' + c + ')';
  else if (isEnum(f.type)) t = '$table->string(' + c + ', 40)';
  else throw new Error('unmapped type ' + mo.name + '.' + f.name + ': ' + f.type);
  if (f.optional) t += '->nullable()';
  const v = phpDefault(f);
  if (v != null) t += '->default(' + v + ')';
  if (f.type === 'DateTime' && f.default === 'now()' && !f.optional) t += '->useCurrent()';
  if (f.isId && !mo.compositeId) t += '->primary()';
  if (f.unique) t += '->unique()';
  return '            ' + t + ';';
}
let up = '';
let fk = '';
const down = [];
for (const mo of models) {
  const cols = mo.fields.filter((f) => !isModel(f.type));
  const both = cols.some((f) => f.name === 'createdAt') && cols.some((f) => f.name === 'updatedAt');
  const lines = [];
  for (const f of cols) {
    if (both && (f.name === 'createdAt' || f.name === 'updatedAt')) continue;
    lines.push(columnLine(mo, f));
  }
  if (both) lines.push('            $table->timestamps(3);');
  if (mo.compositeId) lines.push('            $table->primary([' + mo.compositeId.map((x) => q(snake(x))).join(', ') + ']);');
  for (const u of mo.uniques) lines.push('            $table->unique([' + u.map((x) => q(snake(x))).join(', ') + '], ' + q(idxName(mo.table, u.map(snake), 'uq')) + ');');
  for (const ix of mo.indexes) lines.push('            $table->index([' + ix.map((x) => q(snake(x))).join(', ') + '], ' + q(idxName(mo.table, ix.map(snake), 'ix')) + ');');
  up += '        Schema::create(' + q(mo.table) + ', function (Blueprint $table) {\n' + lines.join('\n') + '\n        });\n\n';
  down.unshift(mo.table);
  const fks = mo.fields.filter((f) => f.rel && f.rel.fields.length);
  if (fks.length) {
    const l = fks.map((f) => {
      const opt = f.rel.fields.every((n) => field(mo, n).optional);
      const act = f.rel.onDelete === 'Cascade' ? 'cascadeOnDelete' : opt ? 'nullOnDelete' : 'restrictOnDelete';
      return '            $table->foreign([' + f.rel.fields.map((n) => q(snake(n))).join(', ') + '], ' + q(idxName(mo.table, f.rel.fields.map(snake), 'fk')) + ')'
        + '->references([' + f.rel.references.map((n) => q(snake(n))).join(', ') + '])->on(' + q(byName[f.type].table) + ')->' + act + '();';
    });
    fk += '        Schema::table(' + q(mo.table) + ', function (Blueprint $table) {\n' + l.join('\n') + '\n        });\n';
  }
}
const head = [
  '<?php', '',
  '// GENERATED by tools/prisma-to-laravel.mjs from docs/reference/schema.prisma.',
  '// Do not hand-edit: change the generator, or add a new migration for schema changes.', '',
  'use Illuminate\\Database\\Migrations\\Migration;',
  'use Illuminate\\Database\\Schema\\Blueprint;',
  'use Illuminate\\Support\\Facades\\Schema;', '',
  'return new class extends Migration', '{', '',
].join('\n');
fs.writeFileSync(path.join(ROOT, 'database/migrations/2026_09_17_000000_create_scm_schema.php'),
  head + '    public function up(): void\n    {\n' + up + '    }\n\n    public function down(): void\n    {\n        Schema::disableForeignKeyConstraints();\n'
  + down.map((t) => '        Schema::dropIfExists(' + q(t) + ');').join('\n') + '\n        Schema::enableForeignKeyConstraints();\n    }\n};\n');
fs.writeFileSync(path.join(ROOT, 'database/migrations/2026_09_17_000001_add_scm_foreign_keys.php'),
  head + '    public function up(): void\n    {\n' + fk + '    }\n\n    public function down(): void\n    {\n        // Foreign keys are dropped together with their tables in the schema migration.\n    }\n};\n');

// ---------- models ----------
const modelsDir = path.join(ROOT, 'app/Models');
fs.mkdirSync(modelsDir, { recursive: true });
function otherSide(mo, f) { // relation field(s) on the related model that own the FK back to `mo`
  return byName[f.type].fields.filter((x) => x.type === mo.name && x.rel && x.rel.fields.length && (x.rel.name || null) === ((f.rel && f.rel.name) || null));
}
const relWarnings = [];
// Per-model additions the schema cannot express.
const EXTRA = {
  User: {
    implements: '\\Illuminate\\Contracts\\Auth\\Authenticatable',
    traits: ['\\Illuminate\\Auth\\Authenticatable'],
    hidden: ['password_hash'],
    props: ["\n    public function getAuthPasswordName()\n    {\n        return 'password_hash';\n    }"],
  },
  RefreshToken: { hidden: ['token_hash'] },
};
for (const mo of models) {
  const cols = mo.fields.filter((f) => !isModel(f.type));
  const idF = cols.find((f) => f.isId);
  const hasCreated = cols.some((f) => f.name === 'createdAt');
  const hasUpdated = cols.some((f) => f.name === 'updatedAt');
  const casts = [];
  const attrs = [];
  for (const f of cols) {
    if (f.list || f.type === 'Json') { casts.push(q(f.col) + " => 'array'"); if (f.default === '[]') attrs.push(q(f.col) + " => '[]'"); }
    else if (f.type === 'Boolean') casts.push(q(f.col) + " => 'boolean'");
    else if (f.type === 'Int' || f.type === 'BigInt') casts.push(q(f.col) + " => 'integer'");
    else if (f.type === 'Float') casts.push(q(f.col) + " => 'float'");
    else if (f.type === 'Decimal') casts.push(q(f.col) + " => 'decimal:" + (f.decimal || [18, 4])[1] + "'");
    else if (f.type === 'DateTime' && f.name !== 'createdAt' && f.name !== 'updatedAt') casts.push(q(f.col) + " => 'datetime'");
  }
  const rels = [];
  const used = new Set();
  for (const f of mo.fields.filter((x) => isModel(x.type))) {
    const R = '\\App\\Models\\' + f.type + '::class';
    if (f.rel && f.rel.fields.length) {
      used.add('BelongsTo');
      rels.push('    public function ' + f.name + '(): BelongsTo\n    {\n        return $this->belongsTo(' + R + ', ' + q(snake(f.rel.fields[0])) + ', ' + q(snake(f.rel.references[0])) + ');\n    }');
    } else {
      const os = otherSide(mo, f);
      if (os.length !== 1) { relWarnings.push(mo.name + '.' + f.name + ' -> ' + f.type + ': ' + os.length + ' candidate FKs'); continue; }
      const kind = f.list ? 'HasMany' : 'HasOne';
      used.add(kind);
      rels.push('    public function ' + f.name + '(): ' + kind + '\n    {\n        return $this->' + (f.list ? 'hasMany' : 'hasOne') + '(' + R + ', ' + q(snake(os[0].rel.fields[0])) + ', ' + q(snake(os[0].rel.references[0])) + ');\n    }');
    }
  }
  const uses = [...used].map((k) => 'use Illuminate\\Database\\Eloquent\\Relations\\' + k + ';');
  const isUlidPk = !!idF && idF.default === 'cuid()';
  if (isUlidPk) uses.push('use Illuminate\\Database\\Eloquent\\Concerns\\HasUlids;');
  uses.sort();
  const props = ['    protected $table = ' + q(mo.table) + ';'];
  if (mo.compositeId) {
    props.push('    /** Composite key (' + mo.compositeId.map(snake).join(', ') + '): write through the query builder or relations, not save(). */');
    props.push('    protected $primaryKey = null;', '    public $incrementing = false;');
  } else if (idF && !isUlidPk) {
    props.push('    protected $primaryKey = ' + q(idF.col) + ';', '    public $incrementing = false;', "    protected $keyType = 'string';");
  }
  if (!hasCreated && !hasUpdated) props.push('    public $timestamps = false;');
  else {
    if (!hasUpdated) props.push('    const UPDATED_AT = null;');
    if (!hasCreated) props.push('    const CREATED_AT = null;');
  }
  if (attrs.length) props.push('    protected $attributes = [' + attrs.join(', ') + '];');
  const extra = EXTRA[mo.name] || {};
  if (extra.hidden) props.push('    protected $hidden = [' + extra.hidden.map(q).join(', ') + '];');
  if (extra.props) props.push(...extra.props);
  const traitLines = (isUlidPk ? ['    use HasUlids;'] : []).concat((extra.traits || []).map((t) => '    use ' + t + ';'));
  const body ='<?php\n\n// GENERATED by tools/prisma-to-laravel.mjs. Put hand-written behaviour in services or traits, or re-run the generator.\n\n'
    + 'namespace App\\Models;\n\n' + (uses.length ? uses.join('\n') + '\n\n' : '')
    + 'class ' + mo.name + ' extends BaseModel' + (extra.implements ? ' implements ' + extra.implements : '') + '\n{\n' + (traitLines.length ? traitLines.join('\n') + '\n\n' : '') + props.join('\n') + '\n\n'
    + '    protected function casts(): array\n    {\n        return [' + (casts.length ? '\n            ' + casts.join(',\n            ') + ',\n        ' : '') + '];\n    }\n'
    + (rels.length ? '\n' + rels.join('\n\n') + '\n' : '') + '}\n';
  fs.writeFileSync(path.join(modelsDir, mo.name + '.php'), body);
}
fs.writeFileSync(path.join(ROOT, 'docs/reference/enums.json'), JSON.stringify(enums, null, 2));
console.log('models: ' + models.length + ', enums: ' + Object.keys(enums).length + ', ulid columns: ' + ulid.size);
if (relWarnings.length) console.log('relation warnings:\n  ' + relWarnings.join('\n  '));

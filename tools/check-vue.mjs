// Static check for Vue pages without bundling (safe to run while other people edit other folders).
//   node tools/check-vue.mjs resources/js/pages/sales [more paths…]
// For every .vue / .js file under the given paths it verifies:
//   1. the SFC parses and its <script setup> + <template> compile;      2. the generated JS is syntactically valid;
//   3. every relative / '@/…' import resolves to a file;                 4. every NAMED import exists in the target module;
//   5. every PascalCase component used in a template is imported.
// Exit code 1 when anything is wrong.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SRC = path.join(ROOT, 'resources/js');
const targets = process.argv.slice(2);
if (!targets.length) { console.error('usage: node tools/check-vue.mjs <dir-or-file> [...]'); process.exit(2); }

const BUILTIN = new Set(['RouterLink', 'RouterView', 'Teleport', 'Transition', 'TransitionGroup', 'KeepAlive', 'Suspense', 'Component']);
const problems = [];
const report = (file, msg) => problems.push(`${path.relative(ROOT, file).replace(/\\/g, '/')}: ${msg}`);

function walk(p, out = []) {
  const st = fs.statSync(p);
  if (st.isDirectory()) fs.readdirSync(p).forEach((f) => walk(path.join(p, f), out));
  else if (/\.(vue|js)$/.test(p)) out.push(p);
  return out;
}
function resolveImport(from, spec) {
  let base;
  if (spec.startsWith('@/')) base = path.join(SRC, spec.slice(2));
  else if (spec.startsWith('.')) base = path.resolve(path.dirname(from), spec);
  else return null; // package import
  for (const c of [base, base + '.js', base + '.vue', base + '.json', path.join(base, 'index.js')]) if (fs.existsSync(c) && fs.statSync(c).isFile()) return c;
  return false;
}
const exportCache = new Map();
function exportsOf(file) {
  if (exportCache.has(file)) return exportCache.get(file);
  const names = new Set();
  let all = false;
  if (file.endsWith('.js')) {
    const s = fs.readFileSync(file, 'utf8');
    for (const m of s.matchAll(/export\s+(?:async\s+)?(?:const|let|var|function\*?|class)\s+([A-Za-z_$][\w$]*)/g)) names.add(m[1]);
    for (const m of s.matchAll(/export\s+(?:const|let|var)\s*\{([\s\S]*?)\}\s*=/g)) m[1].split(',').forEach((x) => { const n = x.trim().split(':').pop().trim(); if (n) names.add(n); });
    for (const m of s.matchAll(/export\s*\{([\s\S]*?)\}/g)) m[1].split(',').forEach((x) => { const n = x.trim().split(/\s+as\s+/).pop().trim(); if (n) names.add(n); });
    if (/export\s+default\b/.test(s)) names.add('default');
    if (/export\s*\*\s*from/.test(s)) all = true;
  } else all = true; // .vue / .json: default import only, not checked by name
  const r = { names, all };
  exportCache.set(file, r);
  return r;
}
function checkImports(file, rawCode) {
  // ignore import-looking text inside comments
  const code = rawCode.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|\s)\/\/.*$/gm, '$1');
  const imported = new Set();
  for (const m of code.matchAll(/import\s+([\s\S]*?)\s+from\s+['"]([^'"]+)['"]/g)) {
    const [, clause, spec] = m;
    const def = clause.match(/^([A-Za-z_$][\w$]*)/);
    if (def) imported.add(def[1]);
    const named = clause.match(/\{([\s\S]*)\}/);
    const list = named ? named[1].split(',').map((x) => x.trim()).filter(Boolean) : [];
    list.forEach((x) => imported.add(x.split(/\s+as\s+/).pop().trim()));
    const target = resolveImport(file, spec);
    if (target === false) { report(file, `cannot resolve import '${spec}'`); continue; }
    if (!target || !list.length) continue;
    const ex = exportsOf(target);
    if (ex.all) continue;
    for (const item of list) { const name = item.split(/\s+as\s+/)[0].trim(); if (!ex.names.has(name)) report(file, `'${name}' is not exported by '${spec}'`); }
  }
  for (const m of code.matchAll(/import\(\s*['"]([^'"]+)['"]\s*\)/g)) if (resolveImport(file, m[1]) === false) report(file, `cannot resolve dynamic import '${m[1]}'`);
  return imported;
}
function syntaxCheck(file, js) {
  const tmp = path.join(os.tmpdir(), `check-vue-${process.pid}-${Math.random().toString(36).slice(2)}.mjs`);
  fs.writeFileSync(tmp, js);
  try { execFileSync(process.execPath, ['--check', tmp], { stdio: 'pipe' }); }
  catch (e) { report(file, 'generated JS does not parse: ' + String(e.stderr || e.message).split('\n').filter((l) => /Error/.test(l)).slice(0, 1).join(' ')); }
  finally { fs.rmSync(tmp, { force: true }); }
}

// Native dialogs block the page, cannot be styled or translated and throw in embedded browsers: use confirm() / ask()
// from stores/ui.js.
function nativeDialogs(file, source) {
  const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
  for (const m of code.matchAll(/\bwindow\.(prompt|alert|confirm)\s*\(/g)) report(file, `window.${m[1]}() is not allowed — use confirm() / ask() from stores/ui.js`);
}

let count = 0;
for (const t of targets) {
  const abs = path.resolve(ROOT, t);
  if (!fs.existsSync(abs)) { console.error('not found: ' + t); process.exit(2); }
  for (const file of walk(abs)) {
    count++;
    const source = fs.readFileSync(file, 'utf8');
    nativeDialogs(file, source);
    if (file.endsWith('.js')) { checkImports(file, source); syntaxCheck(file, source); continue; }
    const { descriptor, errors } = parse(source, { filename: file });
    if (errors.length) { errors.forEach((e) => report(file, 'parse: ' + e.message)); continue; }
    let imported = new Set();
    if (descriptor.scriptSetup || descriptor.script) {
      try {
        const compiled = compileScript(descriptor, { id: 'check', inlineTemplate: false });
        imported = checkImports(file, (descriptor.scriptSetup || descriptor.script).content);
        syntaxCheck(file, compiled.content);
        Object.keys(compiled.bindings || {}).forEach((b) => imported.add(b));
      } catch (e) { report(file, 'script: ' + e.message.split('\n')[0]); }
    }
    if (descriptor.template) {
      const r = compileTemplate({ source: descriptor.template.content, filename: file, id: 'check' });
      r.errors.forEach((e) => report(file, 'template: ' + (e.message || e)));
      // Templates only see the component's own bindings: `window.x` there is `undefined.x` at click time.
      for (const m of r.code.matchAll(/_ctx\.(window|document|localStorage|sessionStorage|navigator)\b/g)) report(file, `template uses the browser global \`${m[1]}\` — wrap it in a function in <script setup>`);
      const used = new Set([...descriptor.template.content.matchAll(/<([A-Z][A-Za-z0-9]*)[\s/>]/g)].map((m) => m[1]));
      for (const tag of used) if (!BUILTIN.has(tag) && !imported.has(tag)) report(file, `<${tag}> is used in the template but never imported`);
    }
  }
}
if (problems.length) { console.error(problems.join('\n')); console.error(`\n${problems.length} problem(s) in ${count} file(s)`); process.exit(1); }
console.log(`OK — ${count} file(s) checked`);

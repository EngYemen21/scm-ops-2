// The folders of the documents tool as a tree. GET /workspace/folders answers a flat list of the folders the caller
// may read — `{ id, parentId, parentHidden, name, sequence, … }` — and the page builds the tree from it:
// a folder whose parent he may not read (`parentHidden`) or that is not in the list stands at the top level.
import { arr } from './workspace';

const byOrder = (a, b) => (a.sequence ?? 0) - (b.sequence ?? 0) || String(a.name ?? '').localeCompare(String(b.name ?? ''));

/** `{ byId, kids }` — the folders by id, and the children of each id in display order ('' = the top level). */
export function folderIndex(folders) {
  const list = arr(folders).filter((f) => f && f.id);
  const byId = new Map(list.map((f) => [f.id, f]));
  const kids = new Map();
  for (const f of list) {
    const parent = f.parentId && !f.parentHidden && byId.has(f.parentId) && f.parentId !== f.id ? f.parentId : '';
    if (!kids.has(parent)) kids.set(parent, []);
    kids.get(parent).push(f);
  }
  for (const rows of kids.values()) rows.sort(byOrder);
  return { byId, kids, list };
}

/**
 * The tree in reading order: `[{ folder, depth, hasChildren }]`. `closed` (a Set of ids) folds the children of those
 * folders. Folders that point at each other in a circle are listed at the top level rather than lost.
 */
export function folderRows(folders, closed = null) {
  const { kids, list } = folderIndex(folders);
  const out = [];
  const seen = new Set();
  // the whole tree is walked (so that nothing is taken for lost); what stands under a folded folder is left out
  const walk = (parent, depth, folded) => {
    for (const f of kids.get(parent) || []) {
      if (seen.has(f.id)) continue;
      seen.add(f.id);
      const hasChildren = (kids.get(f.id) || []).some((c) => !seen.has(c.id));
      if (!folded) out.push({ folder: f, depth, hasChildren });
      if (hasChildren) walk(f.id, depth + 1, folded || !!(closed && closed.has(f.id)));
    }
  };
  walk('', 0, false);
  for (const f of list) if (!seen.has(f.id)) { seen.add(f.id); out.push({ folder: f, depth: 0, hasChildren: false }); }
  return out;
}

/** From the top level down to the folder: `[{ id, name }]`, as far as the caller may see. */
export function folderPath(folders, id) {
  const { byId } = folderIndex(folders);
  const path = [];
  const seen = new Set();
  let f = byId.get(id);
  while (f && !seen.has(f.id)) {
    seen.add(f.id);
    path.unshift({ id: f.id, name: f.name });
    f = f.parentId && !f.parentHidden ? byId.get(f.parentId) : null;
  }
  return path;
}

/** The ids of a folder and of everything under it. */
export function folderBranch(folders, id) {
  const { kids } = folderIndex(folders);
  const out = new Set();
  const walk = (x) => { if (out.has(x)) return; out.add(x); for (const c of kids.get(x) || []) walk(c.id); };
  if (id) walk(id);
  return out;
}

/** Select options that say where each folder stands — «أ / ب / ج» — for the folders `keep` accepts. */
export function folderOptions(folders, keep = () => true) {
  return folderRows(folders).filter((r) => keep(r.folder)).map((r) => ({ v: r.folder.id, l: folderPath(folders, r.folder.id).map((p) => String(p.name ?? '')).join(' / ') }));
}

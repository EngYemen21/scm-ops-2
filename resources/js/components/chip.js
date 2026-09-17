import { getLang } from '../i18n';

const NEUTRAL = { fg: '#55506a', bg: '#F1EFF6' };

/** `{ label, fg, bg }` of a status key from a label map (usable outside components, e.g. CSV export). */
export function chipStyle(map, k, lang = getLang()) {
  const s = k != null ? map?.[k] : undefined;
  if (!s) return { label: k ?? '—', ...NEUTRAL };
  return { label: lang === 'ar' ? s.ar : s.en, fg: s.fg || NEUTRAL.fg, bg: s.bg || NEUTRAL.bg };
}

import { bi } from '../i18n';

/** A select / pill option is `[value, label]` or `{ v, l, disabled }`. */
export const optV = (o) => (Array.isArray(o) ? o[0] : o.v);
export const optL = (o) => bi(Array.isArray(o) ? o[1] : o.l);

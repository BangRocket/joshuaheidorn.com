// Date math and derived flags, ported from the prototype. These operate on the
// raw job data fetched from the API — nothing here is persisted.
import { ACTIVE } from './statuses.js';

const DAY = 86400000;

export function today() {
  const d = new Date();
  d.setHours(0, 0, 0, 0);
  return d;
}

/** Whole days between a YYYY-MM-DD date and today (never negative). */
export function daysSince(dstr) {
  if (!dstr) return 0;
  return Math.max(0, Math.round((today() - new Date(dstr + 'T00:00:00')) / DAY));
}

/** Active job whose last update is older than the stale threshold. */
export function isStale(job, staleDays) {
  return ACTIVE.includes(job.status) && daysSince(job.updated) > staleDays;
}

/** "Jun 4" — appends the year only when it differs from the current one. */
export function fmtDate(dstr) {
  if (!dstr) return '—';
  const d = new Date(dstr + 'T00:00:00');
  const base = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  return d.getFullYear() !== today().getFullYear() ? `${base}, ${d.getFullYear()}` : base;
}

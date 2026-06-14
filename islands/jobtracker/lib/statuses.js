// Status vocabulary, mirrored from the prototype. Order matters: it defines the
// pipeline progression (used for sorting and the funnel).
export const STATUSES = [
  { k: 'saved', label: 'Saved' },
  { k: 'applied', label: 'Applied' },
  { k: 'submitted', label: 'Submitted' },
  { k: 'interviewed', label: 'Interviewed' },
  { k: 'offer', label: 'Offer' },
  { k: 'rejected', label: 'Rejected' },
  { k: 'ghosted', label: 'Ghosted' },
];

export const SMAP = Object.fromEntries(STATUSES.map((s, i) => [s.k, { ...s, i }]));

export const ACTIVE = ['applied', 'submitted', 'interviewed'];
export const CLOSED = ['rejected', 'ghosted'];

// Thin fetch wrapper around the PHP REST API. Every call returns parsed JSON or
// throws an Error carrying the server's message.

// Relative API path — resolves against the page URL, so it works unchanged
// whether the app is served at a subdomain root (https://jobs.example.com/)
// or a subpath (https://example.com/jobs/). The app is a single page served
// with a trailing slash, so 'api/…' always resolves correctly.
const API = 'api';

async function request(method, url, body) {
  const opts = { method, headers: {} };
  if (body !== undefined) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }
  const res = await fetch(url, opts);
  const text = await res.text();
  const data = text ? JSON.parse(text) : null;
  if (!res.ok) {
    throw new Error(data?.error || `Request failed (${res.status})`);
  }
  return data;
}

export const api = {
  listJobs: () => request('GET', `${API}/jobs`),
  createJob: (job) => request('POST', `${API}/jobs`, job),
  updateJob: (id, job) => request('PUT', `${API}/jobs/${id}`, job),
  deleteJob: (id) => request('DELETE', `${API}/jobs/${id}`),
  getSettings: () => request('GET', `${API}/settings`),
  updateSettings: (patch) => request('PUT', `${API}/settings`, patch),
};

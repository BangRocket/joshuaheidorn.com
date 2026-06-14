// Thin fetch wrapper around the PHP REST API. Every call returns parsed JSON or
// throws an Error carrying the server's message.

const API = '/jobs/api';

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

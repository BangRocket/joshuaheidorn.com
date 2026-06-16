// Thin fetch wrapper around the Tacta JSON API. Returns parsed JSON or throws.
const API = "/tacta/api";

async function request(method, url, body) {
  const opts = { method, headers: {} };
  if (body !== undefined) {
    opts.headers["Content-Type"] = "application/json";
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
  deck: () => request("GET", `${API}/deck`),
  createGame: () => request("POST", `${API}/games`, {}),
  join: (code, name, color) => request("POST", `${API}/games/${code}/join`, { name, color }),
  start: (code) => request("POST", `${API}/games/${code}/start`, {}),
  move: (code, move) => request("POST", `${API}/games/${code}/moves`, move),
  state: (code, since) => request("GET", `${API}/games/${code}/state?since=${since}`),
};

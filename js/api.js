/* PMS — unified fetch client */
const wb = {
  base: 'api/',

  _check(res) {
    if (res.status === 401 && !location.pathname.includes('login.html')) {
      location.href = 'login.html';
      throw new Error('Session expired');
    }
    return res;
  },

  async _run(path, opts) {
    const res = await fetch(this.base + path, opts);
    this._check(res);
    const text = await res.text();
    let data = null;
    try { data = JSON.parse(text); } catch { data = null; }
    if (!res.ok) {
      const msg = (data && data.error) ? data.error : `Request failed (${res.status})`;
      const err = new Error(msg);
      err.status = res.status;
      throw err;
    }
    return data;
  },

  get(path) {
    return this._run(path, { headers: { 'Accept': 'application/json' } });
  },
  post(path, json) {
    return this._run(path, this._json(json, 'POST'));
  },
  put(path, json) {
    return this._run(path, this._json(json, 'PUT'));
  },
  del(path) {
    return this._run(path, { method: 'DELETE', headers: { 'Accept': 'application/json' } });
  },
  _json(json, method) {
    return {
      method,
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(json ?? {})
    };
  }
};

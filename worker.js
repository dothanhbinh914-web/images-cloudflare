const CORS = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Methods': 'GET, POST, DELETE, OPTIONS',
  'Access-Control-Allow-Headers': 'Content-Type',
};

function json(data, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { ...CORS, 'Content-Type': 'application/json' },
  });
}

function err(msg, status = 400) {
  return json({ success: false, errors: [{ message: msg }] }, status);
}

async function cfFetch(method, url, token, body) {
  const init = {
    method,
    headers: { Authorization: `Bearer ${token}` },
  };
  if (body) init.body = body;
  const res = await fetch(url, init);
  const data = await res.json();
  return json(data, res.status);
}

export default {
  async fetch(request) {
    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: CORS });
    }

    const url        = new URL(request.url);
    const action     = url.searchParams.get('action')     ?? '';
    const token      = url.searchParams.get('token')      ?? '';
    const accountId  = url.searchParams.get('account_id') ?? '';

    if (!token || !accountId) return err('Thiếu token hoặc account_id');

    const BASE = `https://api.cloudflare.com/client/v4/accounts/${accountId}/images/v1`;

    switch (action) {

      case 'list': {
        const perPage = url.searchParams.get('per_page') ?? '1000';
        const cursor  = url.searchParams.get('cursor')   ?? '';
        let apiUrl = `${BASE}?per_page=${perPage}`;
        if (cursor) apiUrl += `&continuation_token=${encodeURIComponent(cursor)}`;
        return cfFetch('GET', apiUrl, token);
      }

      case 'detail': {
        const id = url.searchParams.get('id');
        if (!id) return err('Thiếu id');
        return cfFetch('GET', `${BASE}/${id}`, token);
      }

      case 'delete': {
        const id = url.searchParams.get('id');
        if (!id) return err('Thiếu id');
        return cfFetch('DELETE', `${BASE}/${id}`, token);
      }

      case 'upload_url': {
        const body = await request.formData().catch(() => null);
        const imageUrl = body?.get('url') ?? url.searchParams.get('url') ?? '';
        if (!imageUrl) return err('Thiếu url ảnh');

        const fd = new FormData();
        fd.append('url', imageUrl);
        return cfFetch('POST', BASE, token, fd);
      }

      case 'upload_file': {
        const body = await request.formData().catch(() => null);
        const file = body?.get('file');
        if (!file) return err('Không nhận được file');

        const fd = new FormData();
        fd.append('file', file, file.name ?? 'upload.jpg');
        return cfFetch('POST', BASE, token, fd);
      }

      default:
        return err('Action không hợp lệ');
    }
  },
};

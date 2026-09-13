class ApiError extends Error {
  constructor({ status = 0, code = 'NETWORK_ERROR', message = '网络请求失败，请稍后重试。', fields = null } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.fields = fields || {};
  }
}

function parsePayload(payload) {
  if (typeof payload !== 'string') return payload;
  try { return JSON.parse(payload); } catch (_) { return null; }
}

function normalizeResponse(response) {
  const payload = parsePayload(response && response.data);
  if (payload && payload.success === true) return payload.data;
  const error = payload && payload.error ? payload.error : {};
  throw new ApiError({
    status: Number(response && response.statusCode) || 0,
    code: error.code || 'REQUEST_FAILED',
    message: error.message || '服务暂时不可用，请稍后重试。',
    fields: error.fields,
  });
}

function joinUrl(baseUrl, requestPath) {
  return `${String(baseUrl).replace(/\/$/, '')}/${String(requestPath).replace(/^\//, '')}`;
}

function callbackTransport(wx, method, options) {
  return new Promise((resolve, reject) => {
    const callbackOptions = {
      ...options,
      method,
      success: resolve,
      fail: () => reject(new ApiError()),
    };
    if (method === 'UPLOAD') wx.uploadFile(callbackOptions);
    else wx.request(callbackOptions);
  });
}

function createApiClient({ wx, baseUrl, session, reauthenticate }) {
  let refreshPromise = null;
  const refresh = () => {
    if (!refreshPromise) {
      refreshPromise = Promise.resolve()
        .then(() => reauthenticate())
        .catch((error) => {
          session.clear();
          throw error;
        })
        .finally(() => { refreshPromise = null; });
    }
    return refreshPromise;
  };

  async function execute(kind, request, retried = false) {
    const current = session.get();
    const headers = { ...(request.headers || {}) };
    if (request.includeAuth !== false && current && current.token) headers.Authorization = `Bearer ${current.token}`;
    if (kind !== 'UPLOAD') headers['content-type'] = 'application/json';
    const options = kind === 'UPLOAD'
      ? { url: joinUrl(baseUrl, request.path), filePath: request.filePath, name: request.name || 'file', formData: request.formData || {}, header: headers }
      : { url: joinUrl(baseUrl, request.path), data: request.data, header: headers };
    try {
      return normalizeResponse(await callbackTransport(wx, kind === 'UPLOAD' ? 'UPLOAD' : request.method, options));
    } catch (error) {
      if (error instanceof ApiError && error.status === 401 && !retried && request.retryAuth !== false && typeof reauthenticate === 'function') {
        await refresh();
        return execute(kind, request, true);
      }
      if (error instanceof ApiError && error.status === 401) session.clear();
      throw error;
    }
  }

  const request = (method, path, data, options = {}) => execute('REQUEST', { method, path, data, ...options });
  return {
    request,
    get: (path, options) => request('GET', path, undefined, options),
    post: (path, data, options) => request('POST', path, data, options),
    patch: (path, data, options) => request('PATCH', path, data, options),
    delete: (path, options) => request('DELETE', path, undefined, options),
    upload: (path, filePath, options = {}) => execute('UPLOAD', { path, filePath, ...options }),
  };
}

module.exports = { ApiError, normalizeResponse, createApiClient };

const API_BASE = (() => {
    const path = window.location.pathname;
    // If we're at the root or a subfolder, derive API base
    const match = path.match(/^(\/[^/]+\/?)/);
    return (match ? match[1] : '/UDrive/') + 'api';
})();

async function api(endpoint, method = 'GET', data = null) {
    const opts = {
        method,
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
    };
    if (data && method !== 'GET') {
        opts.body = JSON.stringify(data);
    }
    const res = await fetch(`${API_BASE}/${endpoint}`, opts);
    const json = await res.json();
    if (!res.ok) throw new Error(json.error || `HTTP ${res.status}`);
    return json;
}

async function apiUpload(endpoint, formData, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', `${API_BASE}/${endpoint}`);
        xhr.withCredentials = true;
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable && onProgress) {
                onProgress(Math.round((e.loaded / e.total) * 100));
            }
        });
        xhr.addEventListener('load', () => {
            try {
                const json = JSON.parse(xhr.responseText);
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(json);
                } else {
                    reject(new Error(json.error || 'Upload failed'));
                }
            } catch (e) {
                reject(new Error('Invalid response'));
            }
        });
        xhr.addEventListener('error', () => reject(new Error('Network error')));
        xhr.send(formData);
    });
}

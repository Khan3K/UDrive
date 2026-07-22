/* ============================================
   UDRIVE - Main Application
   ============================================ */

let currentUser = null;
let currentView = 'mydrive';
let currentFolderId = null;
let currentFiles = [];
let selectedFileId = null;
let encUnlocked = false;
let cachedDrives = [];
let breadcrumbStack = [];

// ---- Init ----
document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    checkAuth();
});

// ---- Theme ----
function initTheme() {
    const saved = localStorage.getItem('udrive-theme') || 'light';
    document.documentElement.setAttribute('data-theme', saved);
    updateThemeIcon(saved);
}
function toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme');
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('udrive-theme', next);
    updateThemeIcon(next);
}
function updateThemeIcon(theme) {
    const btn = document.getElementById('theme-toggle');
    if (!btn) return;
    btn.innerHTML = theme === 'dark'
        ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>'
        : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
}

// ---- Sidebar Toggle ----
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
}

// ---- Auth ----
async function checkAuth() {
    try {
        const res = await api('auth/check');
        if (res.ok) {
            currentUser = res.user;
            encUnlocked = !!res.user.has_encryption_key;
            showDashboard();
        } else {
            showScreen('auth-screen');
        }
    } catch (e) {
        showScreen('auth-screen');
    }
}

function showScreen(id) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    document.getElementById(id).classList.add('active');
}

function showLogin() {
    document.getElementById('login-form').classList.add('active');
    document.getElementById('register-form').classList.remove('active');
    document.getElementById('auth-error').textContent = '';
    document.querySelectorAll('.auth-tab').forEach((t, i) => t.classList.toggle('active', i === 0));
}
function showRegister() {
    document.getElementById('login-form').classList.remove('active');
    document.getElementById('register-form').classList.add('active');
    document.getElementById('auth-error').textContent = '';
    document.querySelectorAll('.auth-tab').forEach((t, i) => t.classList.toggle('active', i === 1));
}

async function doLogin() {
    const u = document.getElementById('login-username').value.trim();
    const p = document.getElementById('login-password').value;
    const errEl = document.getElementById('auth-error');
    errEl.textContent = '';
    if (!u || !p) { errEl.textContent = 'Fill in all fields'; return; }
    try {
        const res = await api('auth/login', 'POST', { username: u, password: p });
        if (res.ok) {
            currentUser = res.user;
            showDashboard();
        } else {
            errEl.textContent = res.error;
        }
    } catch (e) {
        errEl.textContent = e.message;
    }
}

async function doRegister() {
    const u = document.getElementById('reg-username').value.trim();
    const e = document.getElementById('reg-email').value.trim();
    const p = document.getElementById('reg-password').value;
    const errEl = document.getElementById('auth-error');
    errEl.textContent = '';
    if (!u || !e || !p) { errEl.textContent = 'Fill in all fields'; return; }
    try {
        const res = await api('auth/register', 'POST', { username: u, email: e, password: p });
        if (res.ok) {
            showLogin();
            errEl.textContent = 'Account created! Please sign in.';
            errEl.style.color = 'var(--success)';
        } else {
            errEl.textContent = res.error;
        }
    } catch (e) {
        errEl.textContent = e.message;
    }
}

async function doLogout() {
    await api('auth/logout', 'POST');
    currentUser = null;
    cachedDrives = [];
    showScreen('auth-screen');
}

// ---- Dashboard ----
function showDashboard() {
    showScreen('dashboard-screen');
    document.getElementById('user-info').textContent = currentUser.username;
    updateEncIndicator();
    if (currentUser.is_admin) {
        document.querySelectorAll('.admin-only').forEach(el => el.style.display = '');
    }
    loadStorage();
    loadDrivesList().then(() => {
        if (cachedDrives.length === 0) {
            showNoDriveBanner();
        } else {
            hideNoDriveBanner();
        }
        switchView('mydrive');
    });
}

function showNoDriveBanner() {
    let banner = document.getElementById('no-drive-banner');
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'no-drive-banner';
        banner.className = 'no-drive-banner';
        banner.innerHTML = `
            <div class="no-drive-content">
                <span class="no-drive-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
                </span>
                <div>
                    <strong>No cloud drive connected</strong>
                    <p>Connect a drive to start uploading files.</p>
                </div>
                <button class="btn-primary btn-sm" onclick="switchView('drives')">Connect Drive</button>
            </div>
        `;
        document.getElementById('dashboard-screen').insertBefore(banner, document.querySelector('.app-body'));
    }
    banner.style.display = 'flex';
}

function hideNoDriveBanner() {
    const banner = document.getElementById('no-drive-banner');
    if (banner) banner.style.display = 'none';
}

function updateEncIndicator() {
    const el = document.getElementById('enc-indicator');
    const label = el.querySelector('span');
    if (currentUser.encryption_mode) {
        el.className = encUnlocked ? 'enc-badge active' : 'enc-badge locked';
        label.textContent = encUnlocked ? 'Unlocked' : 'Locked';
    } else {
        el.className = 'enc-badge';
        label.textContent = 'Normal';
    }
}

// ---- Drives ----
async function loadDrivesList() {
    try {
        const res = await api('drives/list');
        if (res.ok) cachedDrives = res.drives;
    } catch (e) {}
    return cachedDrives;
}

async function loadStorage() {
    try {
        const res = await api('drives/info');
        if (res.ok) {
            const s = res.storage;
            if (s.drives.length === 0) {
                document.getElementById('storage-bar').style.width = '0%';
                document.getElementById('storage-text').textContent = 'No drives';
                document.getElementById('storage-breakdown').innerHTML = '';
                return;
            }
            const totalGB = (s.total / 1073741824).toFixed(1);
            const usedGB = (s.used / 1073741824).toFixed(1);
            const pct = s.total > 0 ? Math.min(100, (s.used / s.total * 100)) : 0;
            document.getElementById('storage-bar').style.width = pct + '%';
            document.getElementById('storage-text').textContent = `${usedGB} / ${totalGB} GB`;
            let breakdown = '';
            s.drives.forEach(d => {
                const dUsed = (d.used / 1073741824).toFixed(1);
                const dTotal = (d.total / 1073741824).toFixed(1);
                breakdown += `<div class="drive-line"><span>${d.name}</span><span>${dUsed}/${dTotal} GB</span></div>`;
            });
            document.getElementById('storage-breakdown').innerHTML = breakdown;
        }
    } catch (e) {}
}

// ---- Views ----
function switchView(view) {
    currentView = view;
    if (view !== 'mydrive') {
        currentFolderId = null;
        breadcrumbStack = [];
    }
    const searchInput = document.getElementById('search-input');
    if (searchInput && searchInput.value) { searchInput.value = ''; }
    document.querySelector('.search-results-label')?.remove();

    document.querySelectorAll('.nav-link').forEach(n => n.classList.remove('active'));
    const navEl = document.querySelector(`[data-view="${view}"]`);
    if (navEl) navEl.classList.add('active');

    const fileList = document.getElementById('file-list');
    const drivesView = document.getElementById('drives-view');
    const toolbar = document.getElementById('toolbar');

    if (view === 'drives') {
        fileList.style.display = 'none';
        drivesView.style.display = 'block';
        toolbar.style.display = 'none';
        document.getElementById('admin-view').style.display = 'none';
        loadDrivesView();
    } else if (view === 'admin') {
        fileList.style.display = 'none';
        drivesView.style.display = 'none';
        toolbar.style.display = 'none';
        document.getElementById('admin-view').style.display = 'block';
        loadAdminView();
    } else {
        fileList.style.display = 'block';
        drivesView.style.display = 'none';
        toolbar.style.display = 'flex';
        if (view === 'mydrive') loadFiles();
        else if (view === 'starred') loadStarred();
        else if (view === 'recent') loadRecent();
        else if (view === 'trash') loadTrash();
    }
}

async function loadFiles(folderId = null) {
    currentFolderId = folderId;
    if (!folderId) breadcrumbStack = [];
    try {
        const url = folderId ? `files?folder=${folderId}` : 'files';
        const res = await api(url);
        if (res.ok) {
            currentFiles = res.files;
            renderFiles(res.files);
            updateBreadcrumb();
        }
    } catch (e) {
        toast(e.message, 'error');
    }
}

async function loadStarred() {
    try {
        const res = await api('files/starred');
        if (res.ok) { currentFiles = res.files; renderFiles(res.files); }
    } catch (e) { toast(e.message, 'error'); }
}

async function loadRecent() {
    try {
        const res = await api('files/recent');
        if (res.ok) { currentFiles = res.files; renderFiles(res.files); }
    } catch (e) { toast(e.message, 'error'); }
}

async function loadTrash() {
    try {
        const res = await api('files/trashed');
        if (res.ok) { currentFiles = res.files; renderFiles(res.files); }
    } catch (e) { toast(e.message, 'error'); }
}

// ---- Render Files ----
function getFileIconClass(f) {
    if (f.is_folder) return 'folder';
    const mime = (f.mime_type || '').toLowerCase();
    if (mime.startsWith('image/')) return 'image';
    if (mime.startsWith('video/')) return 'video';
    if (mime.startsWith('audio/')) return 'audio';
    if (mime.includes('pdf') || mime.includes('document') || mime.includes('word')) return 'document';
    if (mime.includes('zip') || mime.includes('rar') || mime.includes('tar') || mime.includes('7z')) return 'archive';
    if (mime.includes('text') || mime.includes('json') || mime.includes('javascript') || mime.includes('html')) return 'code';
    return 'default';
}

function getFileIconSvg(f) {
    const type = getFileIconClass(f);
    switch (type) {
        case 'folder':
            return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>';
        case 'image':
            return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
        case 'video':
            return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>';
        case 'audio':
            return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>';
        case 'document':
            return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';
        case 'archive':
            return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>';
        case 'code':
            return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>';
        default:
            return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>';
    }
}

function renderFiles(files) {
    const container = document.getElementById('file-list');
    if (!files || files.length === 0) {
        let emptyMsg = 'No files here yet.';
        let emptySvg = '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>';
        if (cachedDrives.length === 0) {
            emptyMsg = 'Connect a cloud drive to start uploading.';
            emptySvg = '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>';
        } else if (currentView === 'starred') {
            emptyMsg = 'No starred files.';
            emptySvg = '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
        } else if (currentView === 'recent') {
            emptyMsg = 'No recent files.';
            emptySvg = '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
        } else if (currentView === 'trash') {
            emptyMsg = 'Trash is empty.';
            emptySvg = '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
        }
        container.innerHTML = `<div class="empty-state"><div class="empty-icon">${emptySvg}</div><h3>${emptyMsg}</h3><p>Drop files or click Upload</p>
            ${cachedDrives.length === 0 ? '<button class="btn-primary" style="margin-top:16px" onclick="switchView(\'drives\')">Connect Drive</button>' : ''}</div>`;
        return;
    }

    let html = '<div class="file-list-header"><span>Name</span><span>Drive</span><span>Size</span><span></span></div>';
    files.forEach(f => {
        const iconClass = getFileIconClass(f);
        const iconSvg = getFileIconSvg(f);
        const size = f.is_folder ? '' : formatSize(f.size);
        const driveName = f.drive_name || f.drive_provider || '';
        const starBadge = f.starred
            ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="#f59e0b" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" style="margin-left:6px;flex-shrink:0"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>'
            : '';
        html += `
            <div class="file-row" data-id="${f.id}" data-is-folder="${f.is_folder}"
                 onclick="onFileClick(${f.id}, ${f.is_folder})"
                 oncontextmenu="onFileContext(event, ${f.id})">
                <div class="file-name">
                    <span class="file-icon ${iconClass}">${iconSvg}</span>
                    <span class="file-name-text">${esc(f.name)}${starBadge}</span>
                </div>
                <div class="file-meta">${esc(driveName)}</div>
                <div class="file-meta">${size}</div>
                <div class="file-actions">
                    ${!f.is_folder ? `<button class="file-action-btn" onclick="event.stopPropagation(); downloadFile(${f.id})" title="Download">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    </button>` : ''}
                    <button class="file-action-btn" onclick="event.stopPropagation(); onFileContext(event, ${f.id})" title="More">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                    </button>
                </div>
            </div>`;
    });
    container.innerHTML = html;
}

// ---- Helpers ----
function formatSize(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0, s = bytes;
    while (s >= 1024 && i < units.length - 1) { s /= 1024; i++; }
    return s.toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
}

function esc(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

// ---- Search ----
let searchDebounce = null;
async function onSearchInput(query) {
    clearTimeout(searchDebounce);
    if (!query || query.length < 1) {
        document.querySelector('.search-results-label')?.remove();
        refreshCurrentView();
        return;
    }
    searchDebounce = setTimeout(async () => {
        try {
            const res = await api('files/search?q=' + encodeURIComponent(query));
            if (res.ok) {
                currentFiles = res.files;
                renderSearchResults(res.files, query);
            }
        } catch (e) { toast(e.message, 'error'); }
    }, 300);
}

function renderSearchResults(files, query) {
    const fileList = document.getElementById('file-list');
    document.querySelector('.search-results-label')?.remove();

    const label = document.createElement('div');
    label.className = 'search-results-label';
    label.innerHTML = `${files.length} result${files.length !== 1 ? 's' : ''} for "<strong>${esc(query)}</strong>" <span class="clear-search" onclick="clearSearch()">Clear</span>`;
    fileList.parentNode.insertBefore(label, fileList);

    renderFiles(files);
}

function clearSearch() {
    document.getElementById('search-input').value = '';
    document.querySelector('.search-results-label')?.remove();
    refreshCurrentView();
}

// ---- File Click ----
function onFileClick(id, isFolder) {
    if (isFolder) {
        const folder = currentFiles.find(f => f.id === id);
        if (folder) breadcrumbStack.push({ id: folder.id, name: folder.name });
        loadFiles(id);
    } else {
        showFileDetails(id);
    }
}

function updateBreadcrumb() {
    let html = '<span onclick="loadFiles(null)">My Drive</span>';
    breadcrumbStack.forEach((item, i) => {
        html += ' <span style="color:var(--text-faint)">/</span> ';
        if (i === breadcrumbStack.length - 1) {
            html += `<span style="font-weight:600;color:var(--text)">${esc(item.name)}</span>`;
        } else {
            html += `<span onclick="navigateToFolder(${item.id}, ${i})">${esc(item.name)}</span>`;
        }
    });
    document.getElementById('breadcrumb').innerHTML = html;
}

function navigateToFolder(folderId, stackIndex) {
    breadcrumbStack = breadcrumbStack.slice(0, stackIndex + 1);
    loadFiles(folderId);
}

// ---- Context Menu ----
function onFileContext(e, fileId) {
    e.preventDefault();
    e.stopPropagation();
    selectedFileId = fileId;
    const file = currentFiles.find(f => f.id === fileId);
    if (!file) return;

    let items = [];
    if (currentView === 'trash') {
        items = [
            { label: 'Restore', icon: 'restore', action: `restoreFile(${fileId})` },
            { label: 'Delete Permanently', icon: 'delete', action: `permanentDelete(${fileId})`, danger: true },
        ];
    } else {
        if (!file.is_folder) {
            items.push({ label: 'Download', icon: 'download', action: `downloadFile(${fileId})` });
        }
        items.push({ label: 'Rename', icon: 'rename', action: `showRenameDialog(${fileId})` });
        items.push({ label: file.starred ? 'Unstar' : 'Star', icon: 'star', action: `toggleStar(${fileId})` });
        if (cachedDrives.length > 1) {
            items.push({ label: 'Copy to Drive', icon: 'copy', action: `showCopyDialog(${fileId})` });
            items.push({ label: 'Move to Drive', icon: 'move', action: `showMoveDialog(${fileId})` });
        }
        items.push({ sep: true });
        items.push({ label: 'Delete', icon: 'delete', action: `deleteFile(${fileId})`, danger: true });
    }

    showContextMenu(e.clientX, e.clientY, items);
}

const ctxIcons = {
    download: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
    rename: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
    star: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
    copy: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
    move: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>',
    delete: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
    restore: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>',
};

function showContextMenu(x, y, items) {
    const menu = document.getElementById('context-menu');
    let html = '';
    items.forEach(item => {
        if (item.sep) {
            html += '<div class="context-sep"></div>';
        } else {
            const icon = ctxIcons[item.icon] || '';
            html += `<div class="context-item ${item.danger ? 'danger' : ''}" onclick="${item.action}; hideContextMenu()">${icon} ${item.label}</div>`;
        }
    });
    menu.innerHTML = html;
    menu.style.display = 'block';
    menu.style.left = Math.min(x, window.innerWidth - 200) + 'px';
    menu.style.top = Math.min(y, window.innerHeight - menu.offsetHeight - 10) + 'px';
}

function hideContextMenu() {
    document.getElementById('context-menu').style.display = 'none';
}
document.addEventListener('click', hideContextMenu);

// ---- File Operations ----
async function downloadFile(id) {
    window.open(`${API_BASE}/files/download?id=${id}`, '_blank');
}

async function deleteFile(id) {
    if (!confirm('Move to trash?')) return;
    try {
        await api('files/delete', 'POST', { id });
        toast('Moved to trash', 'info');
        loadStorage();
        refreshCurrentView();
    } catch (e) { toast(e.message, 'error'); }
}

async function permanentDelete(id) {
    if (!confirm('Permanently delete? This cannot be undone.')) return;
    try {
        await api('files/delete', 'POST', { id, permanent: true });
        toast('Deleted permanently', 'success');
        loadStorage();
        refreshCurrentView();
    } catch (e) { toast(e.message, 'error'); }
}

async function restoreFile(id) {
    try {
        await api('files/restore', 'POST', { id });
        toast('File restored', 'success');
        refreshCurrentView();
    } catch (e) { toast(e.message, 'error'); }
}

// ---- File Details Panel ----
function showFileDetails(id) {
    const file = currentFiles.find(f => f.id === id);
    if (!file) return;
    const panel = document.getElementById('file-details-panel');
    const body = document.getElementById('file-details-body');
    const iconClass = getFileIconClass(file);
    const iconSvg = getFileIconSvg(file);
    const size = file.is_folder ? 'Folder' : formatSize(file.size);
    const driveName = file.drive_name || file.drive_provider || 'Unknown';
    const created = file.created_at ? new Date(file.created_at).toLocaleString() : '-';
    const modified = file.updated_at ? new Date(file.updated_at).toLocaleString() : '-';

    let actionsHtml = '<div class="details-actions">';
    if (!file.is_folder) {
        actionsHtml += `<button class="btn-primary" onclick="downloadFile(${file.id})">Download</button>`;
    }
    actionsHtml += `<button class="btn-secondary" onclick="showRenameDialog(${file.id})">Rename</button>`;
    actionsHtml += `<button class="btn-secondary" onclick="toggleStar(${file.id})">${file.starred ? 'Unstar' : 'Star'}</button>`;
    actionsHtml += `<button class="btn-danger" onclick="deleteFile(${file.id})">Delete</button>`;
    actionsHtml += '</div>';

    body.innerHTML = `
        <div class="details-icon"><span class="file-icon ${iconClass}" style="width:56px;height:56px;border-radius:var(--radius-lg);font-size:28px">${iconSvg}</span></div>
        <div class="details-name">${esc(file.name)}</div>
        <div class="details-row"><span class="details-label">Type</span><span class="details-value">${file.is_folder ? 'Folder' : (file.mime_type || 'Unknown')}</span></div>
        <div class="details-row"><span class="details-label">Size</span><span class="details-value">${size}</span></div>
        <div class="details-row"><span class="details-label">Drive</span><span class="details-value">${esc(driveName)}</span></div>
        <div class="details-row"><span class="details-label">Encrypted</span><span class="details-value">${file.is_encrypted ? 'Yes' : 'No'}</span></div>
        <div class="details-row"><span class="details-label">Starred</span><span class="details-value">${file.starred ? 'Yes' : 'No'}</span></div>
        <div class="details-row"><span class="details-label">Created</span><span class="details-value">${created}</span></div>
        <div class="details-row"><span class="details-label">Modified</span><span class="details-value">${modified}</span></div>
        ${actionsHtml}
    `;
    panel.classList.add('open');
}

function closeFileDetails() {
    document.getElementById('file-details-panel').classList.remove('open');
}

async function toggleStar(id) {
    try {
        const res = await api('files/star', 'POST', { id });
        if (res.ok) {
            toast(res.starred ? 'Starred' : 'Unstarred', 'success');
            refreshCurrentView();
        }
    } catch (e) { toast(e.message, 'error'); }
}

function refreshCurrentView() {
    if (currentView === 'mydrive') loadFiles(currentFolderId);
    else if (currentView === 'starred') loadStarred();
    else if (currentView === 'recent') loadRecent();
    else if (currentView === 'trash') loadTrash();
}

// ---- Modals ----
function showModal(title, bodyHtml) {
    document.getElementById('modal-title').textContent = title;
    document.getElementById('modal-body').innerHTML = bodyHtml;
    document.getElementById('modal-overlay').style.display = 'flex';
}
function closeModal() {
    document.getElementById('modal-overlay').style.display = 'none';
}

// ---- Upload Dialog ----
async function showUploadDialog() {
    await loadDrivesList();
    if (cachedDrives.length === 0) {
        showModal('No Drive Connected', `
            <div style="text-align:center;padding:20px 0">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--text-faint)" stroke-width="1.5" stroke-linecap="round"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
                <p style="margin:16px 0 8px;color:var(--text-secondary);font-weight:500">Connect a cloud drive first</p>
                <p style="color:var(--text-muted);font-size:13px;margin-bottom:20px">Google Drive, MEGA, OneDrive, or Dropbox</p>
                <button class="btn-primary" onclick="closeModal(); switchView('drives')">Connect a Drive</button>
            </div>
        `);
        return;
    }

    let folderOptions = '<option value="">Root</option>';
    currentFiles.filter(f => f.is_folder).forEach(f => {
        folderOptions += `<option value="${f.id}">${esc(f.name)}</option>`;
    });

    let driveOptions = '<option value="">Auto (best available)</option>';
    cachedDrives.forEach(d => {
        driveOptions += `<option value="${d.id}">${esc(d.name)} (${formatSize(d.storage_free)} free)</option>`;
    });

    showModal('Upload File', `
        <div class="upload-dropzone" id="dropzone" onclick="document.getElementById('file-input').click()">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--text-faint)" stroke-width="1.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <p>Click or drag a file here</p>
            <div id="upload-filename" class="upload-file-name"></div>
        </div>
        <input type="file" id="file-input" style="display:none" onchange="onFileSelected(this)">
        <div class="upload-options">
            <label><input type="checkbox" id="upload-encrypted"> Encrypt</label>
            <select id="upload-folder">${folderOptions}</select>
            <select id="upload-drive">${driveOptions}</select>
        </div>
        <div class="btn-row">
            <button class="btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn-primary" id="btn-start-upload" onclick="startUpload()" disabled>Upload</button>
        </div>
    `);

    const dz = document.getElementById('dropzone');
    dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragover'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
    dz.addEventListener('drop', e => {
        e.preventDefault();
        dz.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            document.getElementById('file-input').files = e.dataTransfer.files;
            onFileSelected(document.getElementById('file-input'));
        }
    });
}

let selectedUploadFile = null;
function onFileSelected(input) {
    if (input.files.length) {
        selectedUploadFile = input.files[0];
        document.getElementById('upload-filename').textContent = `${selectedUploadFile.name} (${formatSize(selectedUploadFile.size)})`;
        document.getElementById('btn-start-upload').disabled = false;
    }
}

async function startUpload() {
    if (!selectedUploadFile) return;
    const encrypted = document.getElementById('upload-encrypted').checked;
    const folderId = document.getElementById('upload-folder').value || null;
    const driveId = document.getElementById('upload-drive').value || null;

    const fd = new FormData();
    fd.append('file', selectedUploadFile);
    if (folderId) fd.append('folder_id', folderId);
    if (driveId) fd.append('drive_id', driveId);
    if (encrypted) fd.append('encrypted', '1');

    closeModal();
    showProgress(selectedUploadFile.name);

    try {
        const res = await apiUpload('files/upload', fd, pct => updateProgress(pct));
        hideProgress();
        if (res.ok) {
            toast('Uploaded successfully', 'success');
            loadStorage();
            refreshCurrentView();
        } else {
            toast(res.error || 'Upload failed', 'error');
        }
    } catch (e) {
        hideProgress();
        toast(e.message, 'error');
    }
}

// ---- New Folder Dialog ----
function showNewFolderDialog() {
    if (cachedDrives.length === 0) {
        toast('Connect a drive first', 'error');
        return;
    }
    let driveOptions = '<option value="">Auto (best drive)</option>';
    cachedDrives.forEach(d => {
        driveOptions += `<option value="${d.id}">${esc(d.name)} (${formatSize(d.storage_free)} free)</option>`;
    });
    showModal('New Folder', `
        <div class="form-group">
            <label>Folder Name</label>
            <input type="text" id="new-folder-name" placeholder="My Folder" autofocus
                   onkeydown="if(event.key==='Enter')createFolder()">
        </div>
        <div class="form-group">
            <label>Destination Drive</label>
            <select id="new-folder-drive">${driveOptions}</select>
        </div>
        <div class="btn-row">
            <button class="btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn-primary" onclick="createFolder()">Create Folder</button>
        </div>
    `);
    setTimeout(() => document.getElementById('new-folder-name')?.focus(), 100);
}

async function createFolder() {
    const name = document.getElementById('new-folder-name')?.value.trim();
    if (!name) return;
    const driveId = document.getElementById('new-folder-drive')?.value || null;
    try {
        await api('files/folder', 'POST', { name, folder_id: currentFolderId, drive_id: driveId ? parseInt(driveId) : null });
        closeModal();
        toast('Folder created', 'success');
        refreshCurrentView();
    } catch (e) { toast(e.message, 'error'); }
}

// ---- Rename Dialog ----
function showRenameDialog(id) {
    const file = currentFiles.find(f => f.id === id);
    if (!file) return;
    showModal('Rename', `
        <div class="form-group">
            <label>Name</label>
            <input type="text" id="rename-name" value="${esc(file.name)}" autofocus
                   onkeydown="if(event.key==='Enter')doRename(${id})">
        </div>
        <div class="btn-row">
            <button class="btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn-primary" onclick="doRename(${id})">Rename</button>
        </div>
    `);
    setTimeout(() => { const el = document.getElementById('rename-name'); if (el) { el.focus(); el.select(); } }, 100);
}

async function doRename(id) {
    const name = document.getElementById('rename-name')?.value.trim();
    if (!name) return;
    try {
        await api('files/rename', 'POST', { id, name });
        closeModal();
        toast('Renamed', 'success');
        refreshCurrentView();
    } catch (e) { toast(e.message, 'error'); }
}

// ---- Move/Copy Dialogs ----
function showMoveDialog(id) {
    showModal('Move to Drive', `
        <div class="form-group">
            <label>Target Drive</label>
            <select id="move-target-drive"><option value="">Select drive...</option></select>
        </div>
        <div class="btn-row">
            <button class="btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn-primary" onclick="doMove(${id})">Move</button>
        </div>
    `);
    loadDriveOptions('move-target-drive');
}

function showCopyDialog(id) {
    showModal('Copy to Drive', `
        <div class="form-group">
            <label>Target Drive</label>
            <select id="copy-target-drive"><option value="">Select drive...</option></select>
        </div>
        <div class="btn-row">
            <button class="btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn-primary" onclick="doCopy(${id})">Copy</button>
        </div>
    `);
    loadDriveOptions('copy-target-drive');
}

async function loadDriveOptions(selectId) {
    try {
        const res = await api('drives/list');
        if (res.ok) {
            const sel = document.getElementById(selectId);
            res.drives.forEach(d => {
                sel.innerHTML += `<option value="${d.id}">${esc(d.name)} (${formatSize(d.storage_free)} free)</option>`;
            });
        }
    } catch (e) {}
}

async function doMove(id) {
    const driveId = document.getElementById('move-target-drive')?.value;
    if (!driveId) return toast('Select a drive', 'error');
    try {
        await api('files/move', 'POST', { id, target_drive_id: parseInt(driveId) });
        closeModal();
        toast('File moved', 'success');
        loadStorage();
        refreshCurrentView();
    } catch (e) { toast(e.message, 'error'); }
}

async function doCopy(id) {
    const driveId = document.getElementById('copy-target-drive')?.value;
    if (!driveId) return toast('Select a drive', 'error');
    try {
        await api('files/copy', 'POST', { id, target_drive_id: parseInt(driveId) });
        closeModal();
        toast('File copied', 'success');
        loadStorage();
        refreshCurrentView();
    } catch (e) { toast(e.message, 'error'); }
}

// ---- Encryption ----
function showEncryptDialog() {
    if (currentUser.encryption_mode) {
        showModal('Encryption Settings', `
            <p style="margin-bottom:16px;color:var(--text-secondary)">Mode: <strong>Encrypted</strong></p>
            ${encUnlocked
                ? `<p style="color:var(--success);margin-bottom:16px;font-size:13px">Encryption is unlocked. Your files can be decrypted.</p>
                   <button class="btn-primary" onclick="lockEncryption()">Lock Encryption</button>`
                : `<p style="color:var(--text-muted);margin-bottom:16px;font-size:13px">Enter your password to unlock encrypted file access.</p>
                   <div class="form-group">
                       <label>Encryption Password</label>
                       <input type="password" id="enc-password" placeholder="Enter password"
                              onkeydown="if(event.key==='Enter')unlockEncryption()">
                   </div>
                   <div class="btn-row">
                       <button class="btn-secondary" onclick="closeModal()">Cancel</button>
                       <button class="btn-primary" onclick="unlockEncryption()">Unlock</button>
                   </div>`}
            <div style="border-top:1px solid var(--border-light);margin-top:20px;padding-top:20px">
                <button class="btn-danger" onclick="toggleEncMode(false)" style="width:100%">Switch to Normal Mode</button>
            </div>
        `);
    } else {
        showModal('Encryption Settings', `
            <p style="margin-bottom:16px;color:var(--text-secondary)">Mode: <strong>Normal</strong> (no encryption)</p>
            <p style="margin-bottom:20px;color:var(--text-muted);font-size:13px;line-height:1.6">Encrypt files before uploading to cloud storage. You'll need your encryption password to download and view encrypted files.</p>
            <button class="btn-primary" onclick="toggleEncMode(true)" style="width:100%">Enable Encryption</button>
        `);
    }
}

async function unlockEncryption() {
    const pw = document.getElementById('enc-password')?.value;
    if (!pw) return toast('Enter password', 'error');
    try {
        await api('encrypt/unlock', 'POST', { password: pw });
        encUnlocked = true;
        closeModal();
        toast('Encryption unlocked', 'success');
        updateEncIndicator();
    } catch (e) { toast(e.message, 'error'); }
}

async function lockEncryption() {
    await api('encrypt/lock', 'POST');
    encUnlocked = false;
    closeModal();
    toast('Encryption locked', 'info');
    updateEncIndicator();
}

async function toggleEncMode(enable) {
    try {
        await api('encrypt/toggle', 'POST', { enabled: enable });
        currentUser.encryption_mode = enable ? 1 : 0;
        if (!enable) {
            await api('encrypt/lock', 'POST');
            encUnlocked = false;
        }
        closeModal();
        updateEncIndicator();
        toast(enable ? 'Encryption enabled' : 'Normal mode enabled', 'success');
    } catch (e) { toast(e.message, 'error'); }
}

// ---- Drives View ----
async function loadDrivesView() {
    try {
        const res = await api('drives/list');
        if (!res.ok) return;
        const container = document.getElementById('drives-list');
        if (res.drives.length === 0) {
            container.innerHTML = `
                <div class="empty-drives">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--text-faint)" stroke-width="1" stroke-linecap="round"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
                    <p style="font-weight:600;margin-top:12px">No drives connected</p>
                    <p>Connect a cloud drive below to start storing files.</p>
                </div>`;
            return;
        }
        let html = '';
        res.drives.forEach(d => {
            const used = formatSize(d.storage_used);
            const total = formatSize(d.storage_total);
            const free = formatSize(d.storage_free);
            html += `
                <div class="drive-card">
                    <div class="drive-card-left">
                        <div class="drive-icon ${d.provider}">${d.name.charAt(0).toUpperCase()}</div>
                        <div>
                            <div class="drive-name">${esc(d.name)}</div>
                            <div class="drive-storage">${used} / ${total} (${free} free)</div>
                        </div>
                    </div>
                    <div class="drive-card-right">
                        <span class="drive-status">Connected</span>
                        <button class="btn-sm" onclick="disconnectDrive(${d.id})">Remove</button>
                    </div>
                </div>`;
        });
        container.innerHTML = html;
    } catch (e) { toast(e.message, 'error'); }
}

function connectDrive(type) {
    if (type === 'mega') {
        document.getElementById('mega-login-form').style.display = 'block';
        return;
    }
    window.location.href = `${API_BASE}/drives/connect?provider=${type}`;
}

async function connectMega() {
    const email = document.getElementById('mega-email').value;
    const password = document.getElementById('mega-password').value;
    if (!email || !password) return toast('Fill in all fields', 'error');
    const btn = document.querySelector('#mega-login-form .btn-primary');
    btn.disabled = true;
    btn.textContent = 'Connecting...';
    try {
        const res = await api('drives/connect', 'POST', {
            provider: 'mega',
            credentials: { email, password }
        });
        if (res.ok) {
            toast('MEGA connected!', 'success');
            document.getElementById('mega-login-form').style.display = 'none';
            document.getElementById('mega-email').value = '';
            document.getElementById('mega-password').value = '';
            await loadDrivesList();
            loadDrivesView();
            loadStorage();
            hideNoDriveBanner();
        } else {
            toast(res.error || 'Connection failed', 'error');
        }
    } catch (e) {
        toast(e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Connect';
    }
}

async function disconnectDrive(id) {
    if (!confirm('Remove this drive? Files on it will not be deleted.')) return;
    try {
        await api('drives?id=' + id, 'DELETE');
        toast('Drive removed', 'success');
        await loadDrivesList();
        loadDrivesView();
        loadStorage();
        if (cachedDrives.length === 0) showNoDriveBanner();
    } catch (e) { toast(e.message, 'error'); }
}

// ---- Progress ----
function showProgress(filename) {
    document.getElementById('progress-filename').textContent = filename;
    document.getElementById('progress-percent').textContent = '0%';
    document.getElementById('progress-fill').style.width = '0%';
    document.getElementById('progress-bar-container').style.display = 'block';
}
function updateProgress(pct) {
    document.getElementById('progress-percent').textContent = pct + '%';
    document.getElementById('progress-fill').style.width = pct + '%';
}
function hideProgress() {
    document.getElementById('progress-bar-container').style.display = 'none';
}

// ---- Toast ----
function toast(msg, type = 'info') {
    const container = document.getElementById('toast-container');
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    const icons = {
        success: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>',
        error: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        info: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
    };
    el.innerHTML = (icons[type] || icons.info) + '<span>' + msg + '</span>';
    container.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateX(100%)'; el.style.transition = 'all 300ms ease'; setTimeout(() => el.remove(), 300); }, 4000);
}

// ---- Keyboard Shortcuts ----
document.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
        if (document.getElementById('auth-screen').classList.contains('active')) {
            if (document.getElementById('login-form').classList.contains('active')) doLogin();
            else doRegister();
        }
    }
    if (e.key === 'Escape') {
        if (document.getElementById('file-details-panel').classList.contains('open')) {
            closeFileDetails();
        } else if (document.getElementById('modal-overlay').style.display === 'flex') {
            closeModal();
        } else if (document.getElementById('context-menu').style.display === 'block') {
            hideContextMenu();
        }
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        e.preventDefault();
        document.getElementById('search-input')?.focus();
    }
    if (e.key === 'Delete' && selectedFileId && document.getElementById('dashboard-screen').classList.contains('active')) {
        if (currentView === 'trash') permanentDelete(selectedFileId);
        else deleteFile(selectedFileId);
        selectedFileId = null;
    }
});

// ---- Admin Panel ----
async function loadAdminView() {
    try {
        const res = await api('admin/settings');
        if (!res.ok) return;
        const container = document.getElementById('admin-provider-list');
        const appBase = window.location.origin + window.location.pathname.replace(/\/public\/.*$/, '').replace(/\/$/, '');
        const providers = [
            { key: 'google_drive', name: 'Google Drive', color: '#4285f4', idLabel: 'Client ID', secretLabel: 'Client Secret', redirectPath: '/api/drives/callback?provider=google_drive' },
            { key: 'onedrive', name: 'OneDrive', color: '#0078d4', idLabel: 'Client ID', secretLabel: 'Client Secret', redirectPath: '/api/drives/callback?provider=onedrive' },
            { key: 'dropbox', name: 'Dropbox', color: '#0061ff', idLabel: 'App Key', secretLabel: 'App Secret', redirectPath: '/api/drives/callback?provider=dropbox' },
        ];
        let html = '';
        providers.forEach(p => {
            const data = res.settings[p.key] || {};
            const configured = !!data.client_id;
            const redirectUri = appBase + p.redirectPath;
            html += `
                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="admin-card-info">
                            <div class="admin-card-dot" style="background:${p.color}"></div>
                            <div>
                                <div class="admin-card-name">${p.name}</div>
                                <div class="admin-card-status ${configured ? 'configured' : 'not-configured'}">${configured ? 'Configured' : 'Not configured'}</div>
                            </div>
                        </div>
                    </div>
                    <div class="admin-card-body">
                        <div class="form-group">
                            <label>${p.idLabel}</label>
                            <input type="text" id="admin-${p.key}-id" value="${esc(data.client_id || '')}" placeholder="Enter ${p.idLabel}">
                        </div>
                        <div class="form-group">
                            <label>${p.secretLabel}</label>
                            <input type="password" id="admin-${p.key}-secret" value="${esc(data.client_secret || '')}" placeholder="Enter ${p.secretLabel}">
                        </div>
                        <div class="admin-redirect-hint">
                            <label>Authorized Redirect URI</label>
                            <div class="admin-redirect-url" onclick="navigator.clipboard.writeText(this.textContent); toast('Copied!', 'success')">${esc(redirectUri)}<span class="copy-hint">click to copy</span></div>
                            <p class="admin-redirect-note">Add this exact URL in your ${p.name} console's OAuth redirect URIs.</p>
                        </div>
                        <button class="btn-primary" onclick="saveAdminSetting('${p.key}')">Save</button>
                    </div>
                </div>`;
        });
        container.innerHTML = html;
    } catch (e) {
        toast(e.message, 'error');
    }
}

async function saveAdminSetting(provider) {
    const clientId = document.getElementById(`admin-${provider}-id`)?.value.trim();
    const clientSecret = document.getElementById(`admin-${provider}-secret`)?.value.trim();
    if (!clientId) return toast('Client ID is required', 'error');
    try {
        const res = await api('admin/settings', 'POST', { provider, client_id: clientId, client_secret: clientSecret });
        if (res.ok) {
            toast(res.message || 'Saved', 'success');
            loadAdminView();
        } else {
            toast(res.error || 'Save failed', 'error');
        }
    } catch (e) { toast(e.message, 'error'); }
}

// ---- OAuth Callback Handler ----
(function() {
    const params = new URLSearchParams(window.location.search);
    if (params.has('error')) {
        const msg = params.get('error');
        window.history.replaceState({}, '', window.location.pathname);
        setTimeout(() => toast(msg, 'error'), 500);
    } else if (params.has('drive_connected')) {
        window.history.replaceState({}, '', window.location.pathname);
        setTimeout(() => {
            toast('Drive connected!', 'success');
            if (currentUser) loadDrivesList().then(() => loadDrivesView());
        }, 500);
    }
})();

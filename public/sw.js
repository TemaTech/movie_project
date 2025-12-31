/**
 * MUBIRAN Service Worker
 * キャッシュバージョン管理と自動更新を行う
 * 
 * デプロイ時にCACHE_VERSIONが自動更新され、古いキャッシュが削除されます
 */

// キャッシュバージョン - デプロイ時に自動更新される
const CACHE_VERSION = 'v20260101060000';
const CACHE_NAME = `mubiran-cache-${CACHE_VERSION}`;

// キャッシュするスタティックアセット
const STATIC_ASSETS = [
    '/',
    '/site.webmanifest',
];

// インストール時
self.addEventListener('install', (event) => {
    console.log('[SW] Installing version:', CACHE_VERSION);
    // 即座にアクティブ化（待機をスキップ）
    self.skipWaiting();
});

// アクティブ化時 - 古いキャッシュを削除
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating version:', CACHE_VERSION);
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name.startsWith('mubiran-cache-') && name !== CACHE_NAME)
                    .map((name) => {
                        console.log('[SW] Deleting old cache:', name);
                        return caches.delete(name);
                    })
            );
        }).then(() => {
            console.log('[SW] Now controlling all clients');
            // 即座にクライアントを制御
            return self.clients.claim();
        })
    );
});

// フェッチ時のキャッシュ戦略
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    
    // 開発サーバーのHMRリクエストは無視
    if (url.pathname.includes('/@vite') || url.pathname.includes('hot')) {
        return;
    }
    
    // CSS/JSファイルは常にネットワークから取得（Network First）
    // Viteビルド済みアセット（ハッシュ付き）は長期キャッシュ可能
    if (url.pathname.endsWith('.css') || url.pathname.endsWith('.js')) {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    // 成功したらキャッシュに保存
                    if (response.ok) {
                        const responseClone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(event.request, responseClone);
                        });
                    }
                    return response;
                })
                .catch(() => {
                    // オフライン時はキャッシュから
                    return caches.match(event.request);
                })
        );
        return;
    }
    
    // 画像はCache First（パフォーマンス優先）
    if (event.request.destination === 'image') {
        event.respondWith(
            caches.match(event.request).then((cachedResponse) => {
                if (cachedResponse) {
                    // キャッシュがある場合はそれを返し、バックグラウンドで更新
                    fetch(event.request).then((response) => {
                        if (response.ok) {
                            caches.open(CACHE_NAME).then((cache) => {
                                cache.put(event.request, response);
                            });
                        }
                    }).catch(() => {});
                    return cachedResponse;
                }
                // キャッシュがない場合はネットワークから取得
                return fetch(event.request).then((response) => {
                    if (response.ok) {
                        const responseClone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(event.request, responseClone);
                        });
                    }
                    return response;
                });
            })
        );
        return;
    }
    
    // HTMLページはNetwork First
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    if (response.ok) {
                        const responseClone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(event.request, responseClone);
                        });
                    }
                    return response;
                })
                .catch(() => {
                    return caches.match(event.request);
                })
        );
        return;
    }
    
    // その他はNetwork First
    event.respondWith(
        fetch(event.request).catch(() => caches.match(event.request))
    );
});

// メッセージハンドラ（キャッシュクリアリクエスト用）
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    if (event.data && event.data.type === 'CLEAR_CACHE') {
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((name) => caches.delete(name))
            );
        }).then(() => {
            console.log('[SW] All caches cleared');
            event.ports[0].postMessage({ success: true });
        });
    }
});

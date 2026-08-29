import '../css/cinematic.css';
import '../css/movie-modal.css';
import '../css/ai-analysis.css';
import '../css/filter-modal.css';
import '../css/static-site.css';
import { openMovieModal, setUsdJpy } from './static-modal';
import {
    activeFilterCount,
    bindFilterModal,
    filterMovies,
    renderFilterModal,
    updateFilterBadges,
} from './static-filters';
import {
    bindNowPlaying,
    renderNowPlaying,
} from './now-playing';

const PER_PAGE = 100;
const app = document.querySelector('#app');
let siteData = null;
let nowPlayingData = null;
let nowPlayingRequest = null;

const defaultState = () => ({
    view: 'ranking',
    region: 'global',
    category: 'all',
    genres: [],
    years: [],
    matchMode: 'and',
    globalPage: 1,
    japanPage: 1,
    nowSort: 'delta',
});

const state = defaultState();

const escapeHtml = (value = '') => String(value).replace(/[&<>'"]/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;',
}[char]));

const posterUrl = (url) => {
    if (!url) return '';
    if (url.startsWith('http') || url.startsWith('/')) return url;
    return `/${url}`;
};

const posterStyle = (url) => {
    const normalized = posterUrl(url);
    return normalized ? `style="background-image:url('${escapeHtml(normalized)}')"` : '';
};

const titleClass = (title) => {
    const length = [...title].length;
    if (length > 35) return 'title-long';
    if (length > 20) return 'title-medium';
    return 'title-short';
};

const revenueBarWidth = (movie, isJapan) => {
    const revenueVal = movie.boxOffice / 100000000;
    const maxRevenue = isJapan ? 150 : 3;
    return Math.min((revenueVal / maxRevenue) * 100, 100);
};

const showOriginalTitle = (movie) => {
    const country = movie.productionCountry || '';
    return movie.originalTitle
        && movie.originalTitle !== movie.title
        && country !== 'JP'
        && country !== '日本';
};

const aiOverlay = (analysis) => {
    if (!analysis) return '';
    return `<button type="button" class="ai-trigger-btn" aria-label="AI分析を表示">
        <svg class="ai-sparkle-icon" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 0L14.59 9.41L24 12L14.59 14.59L12 24L9.41 14.59L0 12L9.41 9.41L12 0Z"/>
        </svg>
    </button>
    <div class="ai-overlay">
        <span class="ai-overlay-label">AI Analysis</span>
        <div class="ai-overlay-content">${escapeHtml(analysis)}</div>
    </div>`;
};

function parseStateFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const next = defaultState();
    const path = window.location.pathname.replace(/\/+$/, '') || '/';
    if (path === '/now/global') {
        next.view = 'now';
        next.region = 'global';
    } else if (path === '/now') {
        next.view = 'now';
        next.region = params.get('now_region') === 'global' ? 'global' : 'japan';
    } else if (params.get('tab') === 'now') {
        // 旧URL互換: /?tab=now&now_region=global
        next.view = 'now';
        next.region = params.get('now_region') === 'global' ? 'global' : 'japan';
    } else {
        next.view = 'ranking';
        next.region = params.get('tab') === 'japan' ? 'japan' : 'global';
    }
    next.nowSort = ['pace', 'total', 'rank', 'days'].includes(params.get('now_sort')) ? params.get('now_sort') : 'delta';
    next.category = params.get('category') || 'all';
    next.genres = params.get('genres') ? params.get('genres').split(',').filter(Boolean) : [];
    next.years = params.get('years') ? params.get('years').split(',').filter(Boolean) : [];
    next.matchMode = params.get('match_mode') === 'or' ? 'or' : 'and';
    next.globalPage = Math.max(1, Number.parseInt(params.get('global_page') || '1', 10) || 1);
    next.japanPage = Math.max(1, Number.parseInt(params.get('japan_page') || '1', 10) || 1);
    return next;
}

function buildUrl(nextState) {
    if (nextState.view === 'now') {
        const base = nextState.region === 'global' ? '/now/global/' : '/now/';
        const params = new URLSearchParams();
        if (nextState.nowSort !== 'delta') params.set('now_sort', nextState.nowSort);
        const query = params.toString();
        return query ? `${base}?${query}` : base;
    }
    const params = new URLSearchParams();
    if (nextState.region === 'japan') params.set('tab', 'japan');
    if (nextState.category && nextState.category !== 'all') params.set('category', nextState.category);
    if (nextState.genres.length) params.set('genres', nextState.genres.join(','));
    if (nextState.years.length) params.set('years', nextState.years.join(','));
    if (nextState.matchMode && nextState.matchMode !== 'and') params.set('match_mode', nextState.matchMode);
    if (nextState.globalPage > 1) params.set('global_page', String(nextState.globalPage));
    if (nextState.japanPage > 1) params.set('japan_page', String(nextState.japanPage));
    const query = params.toString();
    return query ? `/?${query}` : '/';
}

function syncUrl(replace = false) {
    const url = buildUrl(state);
    const method = replace ? 'replaceState' : 'pushState';
    window.history[method]({}, '', url);
}

function currentPage() {
    return state.region === 'japan' ? state.japanPage : state.globalPage;
}

function setCurrentPage(page) {
    if (state.region === 'japan') {
        state.japanPage = page;
    } else {
        state.globalPage = page;
    }
}

function getRankedMovies(data) {
    const filtered = filterMovies(data[state.region], state);
    return filtered.map((movie, index) => ({ ...movie, rank: index + 1 }));
}

function getPageMovies(rankedMovies) {
    const page = currentPage();
    const start = (page - 1) * PER_PAGE;
    return rankedMovies.slice(start, start + PER_PAGE);
}

function movieCard(movie) {
    const url = posterUrl(movie.posterUrl);
    const subtitle = showOriginalTitle(movie)
        ? `<div class="movie-title-en">${escapeHtml(movie.originalTitle)}</div>` : '';
    const bgImage = url ? `<div class="card-bg-image" style="background-image:url('${escapeHtml(url)}')"></div>` : '';
    return `<article class="top-card rank-${movie.rank} ${movie.isActive ? 'active-movie' : ''}" data-movie-id="${escapeHtml(movie.id)}" role="button" tabindex="0" aria-label="${escapeHtml(movie.title)}の詳細を見る">
        ${movie.isActive ? '<div class="active-badge-card">公開中</div>' : ''}
        <div class="rank-badge">${movie.rank}</div>
        <div class="card-poster ${url ? '' : 'poster-placeholder'}" ${posterStyle(movie.posterUrl)}></div>
        ${bgImage}
        <div class="movie-title ${titleClass(movie.title)}" style="margin-bottom: 2px;">${escapeHtml(movie.title)}</div>
        ${subtitle}
        <div class="revenue-container">
            <div class="revenue-main" style="color: var(--accent-gold);">${escapeHtml(movie.revenue)}</div>
            ${movie.revenueYen ? `<div class="revenue-sub">${escapeHtml(movie.revenueYen)}</div>` : ''}
            ${movie.momentum?.deltaLabel ? `<div class="momentum-badge">${escapeHtml(movie.momentum.deltaLabel)}</div>` : ''}
        </div>
        ${aiOverlay(movie.analysis)}
    </article>`;
}

function movieRow(movie, isJapan) {
    const url = posterUrl(movie.posterUrl);
    const subtitle = showOriginalTitle(movie)
        ? `<span class="movie-title-en">${escapeHtml(movie.originalTitle)}</span>` : '';
    const bgImage = url ? `<div class="list-bg-image" style="background-image:url('${escapeHtml(url)}')"></div>` : '';
    const barWidth = revenueBarWidth(movie, isJapan);
    return `<article class="list-item ${movie.isActive ? 'active-movie' : ''} ${isJapan ? 'is-japan' : ''}" data-movie-id="${escapeHtml(movie.id)}" role="button" tabindex="0" aria-label="${escapeHtml(movie.title)}の詳細を見る">
        ${movie.isActive ? '<div class="active-badge-card">公開中</div>' : ''}
        <div class="revenue-bar-bg" style="width: ${barWidth}%;"></div>
        ${bgImage}
        <div class="list-rank">${movie.rank}</div>
        <div class="list-info">
            <span class="movie-title ${titleClass(movie.title)}">${escapeHtml(movie.title)}</span>
            ${subtitle}
        </div>
        <div class="list-revenue">
            <span class="revenue-main">${escapeHtml(movie.revenue)}</span>
            ${movie.revenueYen ? `<span class="revenue-sub">${escapeHtml(movie.revenueYen)}</span>` : ''}
            ${movie.momentum?.deltaLabel ? `<span class="momentum-badge">${escapeHtml(movie.momentum.deltaLabel)}</span>` : ''}
        </div>
        ${aiOverlay(movie.analysis)}
    </article>`;
}

function renderPagination(totalItems) {
    const totalPages = Math.ceil(totalItems / PER_PAGE);
    if (totalPages <= 1) return '';

    const page = currentPage();
    const prevState = { ...state };
    prevState[state.region === 'japan' ? 'japanPage' : 'globalPage'] = Math.max(1, page - 1);
    const nextState = { ...state };
    nextState[state.region === 'japan' ? 'japanPage' : 'globalPage'] = Math.min(totalPages, page + 1);

    const prev = page > 1
        ? `<a href="${buildUrl(prevState)}" class="pagination-btn" data-page="${page - 1}" rel="prev">&lsaquo; 前へ</a>`
        : '<span class="pagination-btn disabled" aria-disabled="true">&lsaquo; 前へ</span>';
    const next = page < totalPages
        ? `<a href="${buildUrl(nextState)}" class="pagination-btn" data-page="${page + 1}" rel="next">次へ &rsaquo;</a>`
        : '<span class="pagination-btn disabled" aria-disabled="true">次へ &rsaquo;</span>';

    return `<nav role="navigation" aria-label="Pagination Navigation" class="cinematic-pagination">${prev}${next}</nav>`;
}

function findMovie(data, movieId) {
    const pools = [...(data.global || []), ...(data.japan || [])];
    const fromRankings = pools.find((movie) => movie.id === movieId);
    if (fromRankings) return fromRankings;
    const boards = [...(nowPlayingData?.japan?.board || []), ...(nowPlayingData?.global?.board || [])];
    const fromBoard = boards.find((movie) => movie.key === movieId || movie.id === movieId);
    return fromBoard ? { ...fromBoard, id: fromBoard.key || fromBoard.id } : null;
}

function bindAiOverlays() {
    document.querySelectorAll('.ai-trigger-btn').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            event.preventDefault();
            const card = button.closest('.top-card, .list-item');
            const overlay = card?.querySelector('.ai-overlay');
            if (!overlay) return;
            const isVisible = overlay.classList.contains('visible');
            document.querySelectorAll('.ai-overlay.visible').forEach((item) => item.classList.remove('visible'));
            document.querySelectorAll('.ai-trigger-btn.active').forEach((item) => item.classList.remove('active'));
            if (!isVisible) {
                overlay.classList.add('visible');
                button.classList.add('active');
            }
        });
    });

    document.querySelectorAll('.ai-overlay').forEach((overlay) => {
        overlay.addEventListener('click', (event) => {
            event.stopPropagation();
            event.preventDefault();
            overlay.classList.remove('visible');
            overlay.closest('.top-card, .list-item')?.querySelector('.ai-trigger-btn')?.classList.remove('active');
        });
    });
}

function bindMovieInteractions(data) {
    document.querySelectorAll('[data-movie-id]').forEach((element) => {
        const open = () => {
            const movie = findMovie(data, element.dataset.movieId);
            if (movie) openMovieModal(movie);
        };
        element.addEventListener('click', (event) => {
            if (event.target.closest('a')) return;
            open();
        });
        element.addEventListener('keydown', (event) => {
            if (event.target.closest('a')) return;
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                open();
            }
        });
    });
}

function renderFilterTrigger() {
    if (state.view === 'now') return '';
    return `<button type="button" class="filter-trigger-btn filter-mobile" aria-label="絞り込み">
        <svg class="filter-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>
        </svg>
        <span class="filter-text">絞り込み</span>
    </button>`;
}

function headerHtml() {
    const desktopFilter = state.view === 'now' ? '' : `<button type="button" class="filter-trigger-btn filter-desktop" aria-label="絞り込み">
        <svg class="filter-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>
        </svg>
        <span class="filter-text">絞り込み</span>
    </button>`;
    const nowUrl = state.region === 'global' ? '/now/global/' : '/now/';
    const rankingUrl = state.region === 'japan' ? '/?tab=japan' : '/';
    const regionUrl = (region) => buildUrl({ ...state, region });
    return `<header>
        <a href="/" class="logo-link" aria-label="MUBIRAN トップ"><img src="/images/logo.png" alt="MUBIRAN" class="logo-img"></a>
        <div class="header-controls">
            <div class="toggle-container view-toggle" role="group" aria-label="表示切替">
                <a href="${nowUrl}" class="toggle-btn ${state.view === 'now' ? 'active' : ''}" data-view="now" id="btn-now">公開中</a>
                <a href="${rankingUrl}" class="toggle-btn ${state.view === 'ranking' ? 'active' : ''}" data-view="ranking" id="btn-ranking">歴代</a>
            </div>
            <div class="toggle-container region-toggle" role="group" aria-label="地域切替">
                <a href="${regionUrl('japan')}" class="toggle-btn ${state.region === 'japan' ? 'active' : ''}" data-region="japan" id="btn-japan">日本</a>
                <a href="${regionUrl('global')}" class="toggle-btn ${state.region === 'global' ? 'active' : ''}" data-region="global" id="btn-global">世界</a>
            </div>
            ${renderFilterTrigger()}
        </div>
        ${desktopFilter}
    </header>`;
}

function footerHtml() {
    const year = new Date().getFullYear();
    const usdJpy = Number.isFinite(Number(siteData?.usdJpy)) && Number(siteData.usdJpy) > 0
        ? String(Math.round(Number(siteData.usdJpy)))
        : '150';
    return `<footer><div class="container">
        <p class="site-footer-links"><a href="/now/">公開中の動向</a> · <a href="/about/">このサイトについて</a> · <a href="/privacy/">プライバシーポリシー</a> · <a href="/feed.xml">RSS</a></p>
        <p>&copy; ${year} MUBIRAN. All rights reserved.</p>
        <p class="site-footer-attr">
            <a class="tmdb-attr" href="https://www.themoviedb.org/" target="_blank" rel="noreferrer">
                <img src="/images/tmdb-logo.svg" alt="The Movie Database (TMDB)" class="tmdb-logo" width="80" height="10">
            </a>
            日本の歴代興行収入は Wikipedia『<a href="https://ja.wikipedia.org/wiki/${encodeURIComponent('日本歴代興行成績上位の映画一覧')}" target="_blank" rel="noreferrer">日本歴代興行成績上位の映画一覧</a>』（<a href="https://creativecommons.org/licenses/by-sa/4.0/deed.ja" target="_blank" rel="noreferrer">CC BY-SA 4.0</a>）を出典としています。
        </p>
        <p class="site-footer-disclaimer">This website uses TMDB and the TMDB APIs but is not endorsed, certified, or otherwise approved by TMDB.</p>
        <p class="site-footer-disclaimer">世界興収の円換算は 1ドル=${usdJpy}円の概算です。</p>
    </div></footer>`;
}

function ensureNowPlaying() {
    if (nowPlayingData) return Promise.resolve(nowPlayingData);
    if (!nowPlayingRequest) {
        nowPlayingRequest = fetch('/data/now-playing.json')
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('公開中データを読み込めませんでした。'))))
            .then((json) => {
                nowPlayingData = json;
                return json;
            });
    }
    return nowPlayingRequest;
}

function navigate(next) {
    Object.assign(state, next);
    syncUrl();
    if (state.view === 'now') {
        ensureNowPlaying()
            .then(() => render(siteData))
            .catch((error) => { app.innerHTML = `<p class="static-error">${escapeHtml(error.message)}</p>`; });
        return;
    }
    render(siteData);
}

function bindHeader() {
    document.querySelectorAll('[data-view]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            navigate({ view: button.dataset.view });
        });
    });
    document.querySelectorAll('[data-region]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            navigate({ region: button.dataset.region });
        });
    });
}

function renderNowBoard(data) {
    const title = state.region === 'japan' ? '公開中映画の勢い（日本）' : '公開中映画の勢い（世界）';
    app.innerHTML = `${headerHtml()}
    <main class="container">
        <h1 class="page-title now-page-title">${title}</h1>
        ${renderNowPlaying(nowPlayingData, state)}
    </main>
    ${footerHtml()}`;

    bindHeader();
    bindNowPlaying(app, (next) => {
        Object.assign(state, next);
        syncUrl();
        render(data);
    });
    bindMovieInteractions(data);
}

function render(data) {
    if (state.view === 'now') {
        if (!nowPlayingData) {
            ensureNowPlaying()
                .then(() => render(data))
                .catch((error) => { app.innerHTML = `<p class="static-error">${escapeHtml(error.message)}</p>`; });
            return;
        }
        renderNowBoard(data);
        return;
    }

    const allGenres = [...new Set([...data.global, ...data.japan].flatMap((movie) => movie.genres || []))]
        .filter((genre) => genre !== 'アニメーション' && genre !== 'アニメ')
        .sort((a, b) => a.localeCompare(b, 'ja'));

    const rankedMovies = getRankedMovies(data);
    const totalItems = rankedMovies.length;
    const totalPages = Math.max(1, Math.ceil(totalItems / PER_PAGE));

    if (currentPage() > totalPages) {
        setCurrentPage(totalPages);
        syncUrl(true);
    }

    const movies = getPageMovies(rankedMovies);
    const top = movies.filter((movie) => movie.rank <= 3);
    const rest = movies.filter((movie) => movie.rank > 3);
    const isJapan = state.region === 'japan';
    const title = isJapan ? '日本興行収入ランキング' : '世界興行収入ランキング';
    const lastUpdated = isJapan ? data.japanLastUpdated : data.globalLastUpdated;

    app.innerHTML = `${headerHtml()}
    <main class="container">
        <h1 class="page-title" id="page-title">${title}</h1>
        ${movies.length
            ? `<section class="top-rankings">${top.map(movieCard).join('')}</section><section class="ranking-list">${rest.map((movie) => movieRow(movie, isJapan)).join('')}</section>`
            : '<p class="static-empty text-center text-white">データがありません。</p>'}
        ${lastUpdated ? `<div class="text-center mt-4 mb-5"><small class="static-updated">最終更新: ${escapeHtml(lastUpdated)}</small></div>` : ''}
        <div class="d-flex justify-content-center mt-4">${renderPagination(totalItems)}</div>
    </main>
    ${footerHtml()}
    ${renderFilterModal(allGenres, state)}`;

    bindHeader();

    document.querySelectorAll('.pagination-btn[data-page]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            setCurrentPage(Number.parseInt(link.dataset.page, 10));
            syncUrl();
            render(data);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });

    bindFilterModal(state, (nextFilters) => {
        Object.assign(state, nextFilters);
        setCurrentPage(1);
        syncUrl();
        render(data);
    });
    updateFilterBadges(activeFilterCount(state));
    bindMovieInteractions(data);
    bindAiOverlays();
}

function init(data) {
    siteData = data;
    if (data.usdJpy) setUsdJpy(data.usdJpy);
    Object.assign(state, parseStateFromUrl());
    syncUrl(true);
    render(data);

    window.addEventListener('popstate', () => {
        Object.assign(state, defaultState(), parseStateFromUrl());
        render(siteData);
    });
}

fetch('/data/movies.json')
    .then((response) => response.ok ? response.json() : Promise.reject(new Error('ランキングデータを読み込めませんでした。')))
    .then(init)
    .catch((error) => { app.innerHTML = `<p class="static-error">${escapeHtml(error.message)}</p>`; });

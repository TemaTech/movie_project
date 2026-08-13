import '../css/cinematic.css';
import '../css/movie-modal.css';
import '../css/ai-analysis.css';
import '../css/filter-modal.css';
import '../css/static-site.css';
import { openMovieModal } from './static-modal';
import {
    activeFilterCount,
    bindFilterModal,
    filterMovies,
    renderFilterModal,
    updateFilterBadges,
} from './static-filters';

const PER_PAGE = 100;
const app = document.querySelector('#app');
let siteData = null;

const defaultState = () => ({
    tab: 'global',
    category: 'all',
    genres: [],
    years: [],
    matchMode: 'and',
    globalPage: 1,
    japanPage: 1,
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
    next.tab = params.get('tab') === 'japan' ? 'japan' : 'global';
    next.category = params.get('category') || 'all';
    next.genres = params.get('genres') ? params.get('genres').split(',').filter(Boolean) : [];
    next.years = params.get('years') ? params.get('years').split(',').filter(Boolean) : [];
    next.matchMode = params.get('match_mode') === 'or' ? 'or' : 'and';
    next.globalPage = Math.max(1, Number.parseInt(params.get('global_page') || '1', 10) || 1);
    next.japanPage = Math.max(1, Number.parseInt(params.get('japan_page') || '1', 10) || 1);
    return next;
}

function buildUrl(nextState) {
    const params = new URLSearchParams();
    if (nextState.tab && nextState.tab !== 'global') params.set('tab', nextState.tab);
    if (nextState.category && nextState.category !== 'all') params.set('category', nextState.category);
    if (nextState.genres.length) params.set('genres', nextState.genres.join(','));
    if (nextState.years.length) params.set('years', nextState.years.join(','));
    if (nextState.matchMode && nextState.matchMode !== 'and') params.set('match_mode', nextState.matchMode);
    if (nextState.globalPage > 1) params.set('global_page', String(nextState.globalPage));
    if (nextState.japanPage > 1) params.set('japan_page', String(nextState.japanPage));
    const query = params.toString();
    return query ? `?${query}` : window.location.pathname;
}

function syncUrl(replace = false) {
    const url = buildUrl(state);
    const method = replace ? 'replaceState' : 'pushState';
    window.history[method]({}, '', url);
}

function currentPage() {
    return state.tab === 'japan' ? state.japanPage : state.globalPage;
}

function setCurrentPage(page) {
    if (state.tab === 'japan') {
        state.japanPage = page;
    } else {
        state.globalPage = page;
    }
}

function getRankedMovies(data) {
    const filtered = filterMovies(data[state.tab], state);
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
        </div>
        ${aiOverlay(movie.analysis)}
    </article>`;
}

function renderPagination(totalItems) {
    const totalPages = Math.ceil(totalItems / PER_PAGE);
    if (totalPages <= 1) return '';

    const page = currentPage();
    const prevState = { ...state };
    prevState[state.tab === 'japan' ? 'japanPage' : 'globalPage'] = Math.max(1, page - 1);
    const nextState = { ...state };
    nextState[state.tab === 'japan' ? 'japanPage' : 'globalPage'] = Math.min(totalPages, page + 1);

    const prev = page > 1
        ? `<a href="${buildUrl(prevState)}" class="pagination-btn" data-page="${page - 1}" rel="prev">&lsaquo; 前へ</a>`
        : '<span class="pagination-btn disabled" aria-disabled="true">&lsaquo; 前へ</span>';
    const next = page < totalPages
        ? `<a href="${buildUrl(nextState)}" class="pagination-btn" data-page="${page + 1}" rel="next">次へ &rsaquo;</a>`
        : '<span class="pagination-btn disabled" aria-disabled="true">次へ &rsaquo;</span>';

    return `<nav role="navigation" aria-label="Pagination Navigation" class="cinematic-pagination">${prev}${next}</nav>`;
}

function findMovie(data, movieId) {
    return data[state.tab].find((movie) => movie.id === movieId)
        || [...data.global, ...data.japan].find((movie) => movie.id === movieId)
        || null;
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
        element.addEventListener('click', open);
        element.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                open();
            }
        });
    });
}

function renderFilterTrigger() {
    return `<button type="button" class="filter-trigger-btn filter-mobile" aria-label="絞り込み">
        <svg class="filter-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>
        </svg>
        <span class="filter-text">絞り込み</span>
    </button>`;
}

function switchTab(tab, { updateHistory = true } = {}) {
    state.tab = tab;
    if (updateHistory) syncUrl();
    render(siteData);
}

function render(data) {
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
    const isJapan = state.tab === 'japan';
    const title = isJapan ? '日本興行収入ランキング' : '世界興行収入ランキング';
    const lastUpdated = isJapan ? data.japanLastUpdated : data.globalLastUpdated;

    app.innerHTML = `<header>
        <a href="/" class="logo-link" aria-label="MUBIRAN トップ"><img src="/images/logo.png" alt="MUBIRAN" class="logo-img"></a>
        <div class="header-controls">
            <div class="toggle-container">
                <a href="#" class="toggle-btn ${state.tab === 'global' ? 'active' : ''}" data-tab="global" id="btn-global">世界興行収入</a>
                <a href="#" class="toggle-btn ${state.tab === 'japan' ? 'active' : ''}" data-tab="japan" id="btn-japan">日本興行収入</a>
            </div>
            ${renderFilterTrigger()}
        </div>
        <button type="button" class="filter-trigger-btn filter-desktop" aria-label="絞り込み">
            <svg class="filter-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>
            </svg>
            <span class="filter-text">絞り込み</span>
        </button>
    </header>
    <main class="container">
        <h1 class="page-title" id="page-title">${title}</h1>
        ${movies.length
            ? `<section class="top-rankings">${top.map(movieCard).join('')}</section><section class="ranking-list">${rest.map((movie) => movieRow(movie, isJapan)).join('')}</section>`
            : '<p class="static-empty text-center text-white">データがありません。</p>'}
        ${lastUpdated ? `<div class="text-center mt-4 mb-5"><small class="static-updated">最終更新: ${escapeHtml(lastUpdated)}</small></div>` : ''}
        <div class="d-flex justify-content-center mt-4">${renderPagination(totalItems)}</div>
    </main>
    <footer><div class="container"><p class="site-footer-links"><a href="/about/">このサイトについて</a> · <a href="/privacy/">プライバシーポリシー</a></p><p>&copy; ${new Date().getFullYear()} MUBIRAN. All rights reserved.</p><p>Data provided by <a href="https://www.themoviedb.org/" target="_blank" rel="noreferrer">TMDb</a> and <a href="https://ja.wikipedia.org/" target="_blank" rel="noreferrer">Wikipedia</a>.</p></div></footer>
    ${renderFilterModal(allGenres, state)}`;

    document.querySelectorAll('[data-tab]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            switchTab(button.dataset.tab);
        });
    });

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

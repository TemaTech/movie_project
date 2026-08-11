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

const app = document.querySelector('#app');
const state = {
    tab: 'global',
    query: '',
    category: 'all',
    genres: [],
    years: [],
    matchMode: 'and',
};

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
    const revenueVal = isJapan ? movie.boxOffice / 100000000 : movie.boxOffice / 100000000;
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

function render(data) {
    const allGenres = [...new Set([...data.global, ...data.japan].flatMap((movie) => movie.genres || []))]
        .filter((genre) => genre !== 'アニメーション' && genre !== 'アニメ')
        .sort((a, b) => a.localeCompare(b, 'ja'));
    const movies = filterMovies(data[state.tab], state);
    const top = movies.filter((movie) => movie.rank <= 3);
    const rest = movies.filter((movie) => movie.rank > 3);
    const isJapan = state.tab === 'japan';
    const title = isJapan ? '日本興行収入ランキング' : '世界興行収入ランキング';

    app.innerHTML = `<header>
        <a href="/" class="logo-link" aria-label="MUBIRAN トップ"><img src="/images/logo.png" alt="MUBIRAN" class="logo-img"></a>
        <div class="header-controls">
            <div class="toggle-container">
                <button type="button" class="toggle-btn ${state.tab === 'global' ? 'active' : ''}" data-tab="global">世界興行収入</button>
                <button type="button" class="toggle-btn ${state.tab === 'japan' ? 'active' : ''}" data-tab="japan">日本興行収入</button>
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
        <h1 class="page-title">${title}</h1>
        <section class="static-search" aria-label="クイック検索">
            <input id="movie-search" type="search" placeholder="映画タイトル・ジャンルで検索" value="${escapeHtml(state.query)}">
        </section>
        <p class="static-count">${movies.length}作品を表示</p>
        ${movies.length
            ? `<section class="top-rankings">${top.map(movieCard).join('')}</section><section class="ranking-list">${rest.map((movie) => movieRow(movie, isJapan)).join('')}</section>`
            : '<p class="static-empty">条件に一致する映画はありません。</p>'}
        <p class="static-updated">最終更新: ${new Date(data.generatedAt).toLocaleString('ja-JP', { timeZone: 'Asia/Tokyo' })}</p>
    </main>
    <footer><div class="container"><p>&copy; ${new Date().getFullYear()} MUBIRAN.</p><p>Data provided by <a href="https://www.themoviedb.org/" target="_blank" rel="noreferrer">TMDb</a> and <a href="https://ja.wikipedia.org/" target="_blank" rel="noreferrer">Wikipedia</a>.</p></div></footer>
    ${renderFilterModal(allGenres, state)}`;

    document.querySelectorAll('[data-tab]').forEach((button) => button.addEventListener('click', () => {
        state.tab = button.dataset.tab;
        render(data);
    }));
    document.querySelector('#movie-search').addEventListener('input', (event) => {
        state.query = event.target.value;
        render(data);
    });
    bindFilterModal(state, (nextFilters) => {
        Object.assign(state, nextFilters);
        render(data);
    });
    updateFilterBadges(activeFilterCount(state));
    bindMovieInteractions(data);
    bindAiOverlays();
}

fetch('/data/movies.json')
    .then((response) => response.ok ? response.json() : Promise.reject(new Error('ランキングデータを読み込めませんでした。')))
    .then(render)
    .catch((error) => { app.innerHTML = `<p class="static-error">${escapeHtml(error.message)}</p>`; });

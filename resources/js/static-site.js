import '../css/cinematic.css';
import '../css/static-site.css';

const app = document.querySelector('#app');
const state = { tab: 'global', query: '', category: 'all', genre: '' };

const escapeHtml = (value = '') => String(value).replace(/[&<>'"]/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;',
}[char]));

const posterStyle = (url) => url ? `style="background-image:url('${escapeHtml(url)}')"` : '';

function movieCard(movie) {
    const subtitle = movie.originalTitle && movie.originalTitle !== movie.title
        ? `<div class="movie-title-en">${escapeHtml(movie.originalTitle)}</div>` : '';
    const analysis = movie.analysis ? `<p class="static-analysis">${escapeHtml(movie.analysis)}</p>` : '';
    return `<article class="top-card rank-${movie.rank}">
        ${movie.isActive ? '<div class="active-badge-card">公開中</div>' : ''}
        <div class="rank-badge">${movie.rank}</div>
        <div class="card-poster ${movie.posterUrl ? '' : 'poster-placeholder'}" ${posterStyle(movie.posterUrl)}></div>
        <h2 class="movie-title title-short">${escapeHtml(movie.title)}</h2>
        ${subtitle}
        <div class="revenue-container"><div class="revenue-main">${escapeHtml(movie.revenue)}</div>${movie.revenueYen ? `<div class="revenue-sub">${escapeHtml(movie.revenueYen)}</div>` : ''}</div>
        ${analysis}
    </article>`;
}

function movieRow(movie) {
    const subtitle = movie.originalTitle && movie.originalTitle !== movie.title
        ? `<span class="movie-title-en">${escapeHtml(movie.originalTitle)}</span>` : '';
    const analysis = movie.analysis ? `<span class="static-analysis static-analysis-row">${escapeHtml(movie.analysis)}</span>` : '';
    return `<article class="list-item ${movie.isActive ? 'active-movie' : ''}">
        ${movie.isActive ? '<div class="active-badge-card">公開中</div>' : ''}
        <div class="list-rank">${movie.rank}</div>
        <div class="list-info"><span class="movie-title title-short">${escapeHtml(movie.title)}</span>${subtitle}${analysis}</div>
        <div class="list-revenue"><span class="revenue-main">${escapeHtml(movie.revenue)}</span>${movie.revenueYen ? `<span class="revenue-sub">${escapeHtml(movie.revenueYen)}</span>` : ''}</div>
    </article>`;
}

function filterMovies(movies) {
    const query = state.query.trim().toLocaleLowerCase('ja');
    return movies.filter((movie) => {
        const searchable = `${movie.title} ${movie.originalTitle || ''} ${(movie.genres || []).join(' ')}`.toLocaleLowerCase('ja');
        const categoryMatches = state.category === 'all'
            || (state.category === 'anime' && movie.isAnime)
            || (state.category === 'live' && !movie.isAnime);
        return categoryMatches
            && (!state.genre || movie.genres.includes(state.genre))
            && (!query || searchable.includes(query));
    });
}

function render(data) {
    const allGenres = [...new Set([...data.global, ...data.japan].flatMap((movie) => movie.genres || []))]
        .filter((genre) => genre !== 'アニメーション').sort((a, b) => a.localeCompare(b, 'ja'));
    const movies = filterMovies(data[state.tab]);
    const top = movies.filter((movie) => movie.rank <= 3);
    const rest = movies.filter((movie) => movie.rank > 3);
    const title = state.tab === 'global' ? '世界興行収入ランキング' : '日本興行収入ランキング';

    app.innerHTML = `<header>
        <a href="/" class="logo-link" aria-label="MUBIRAN トップ"><img src="/images/logo.png" alt="MUBIRAN" class="logo-img"></a>
        <div class="toggle-container">
          <button class="toggle-btn ${state.tab === 'global' ? 'active' : ''}" data-tab="global">世界興行収入</button>
          <button class="toggle-btn ${state.tab === 'japan' ? 'active' : ''}" data-tab="japan">日本興行収入</button>
        </div>
    </header>
    <main class="container">
      <h1 class="page-title">${title}</h1>
      <section class="static-filters" aria-label="ランキングを絞り込む">
        <input id="movie-search" type="search" placeholder="映画タイトル・ジャンルで検索" value="${escapeHtml(state.query)}">
        <select id="category-filter"><option value="all">すべて</option><option value="anime">アニメ</option><option value="live">実写</option></select>
        <select id="genre-filter"><option value="">すべてのジャンル</option>${allGenres.map((genre) => `<option value="${escapeHtml(genre)}">${escapeHtml(genre)}</option>`).join('')}</select>
      </section>
      <p class="static-count">${movies.length}作品を表示</p>
      ${movies.length ? `<section class="top-rankings">${top.map(movieCard).join('')}</section><section class="ranking-list">${rest.map(movieRow).join('')}</section>` : '<p class="static-empty">条件に一致する映画はありません。</p>'}
      <p class="static-updated">最終更新: ${new Date(data.generatedAt).toLocaleString('ja-JP', { timeZone: 'Asia/Tokyo' })}</p>
    </main>
    <footer><div class="container"><p>&copy; ${new Date().getFullYear()} MUBIRAN.</p><p>Data provided by <a href="https://www.themoviedb.org/" target="_blank" rel="noreferrer">TMDb</a> and <a href="https://ja.wikipedia.org/" target="_blank" rel="noreferrer">Wikipedia</a>.</p></div></footer>`;

    document.querySelectorAll('[data-tab]').forEach((button) => button.addEventListener('click', () => {
        state.tab = button.dataset.tab;
        render(data);
    }));
    const search = document.querySelector('#movie-search');
    search.addEventListener('input', (event) => { state.query = event.target.value; render(data); });
    const category = document.querySelector('#category-filter');
    category.value = state.category;
    category.addEventListener('change', (event) => { state.category = event.target.value; render(data); });
    const genre = document.querySelector('#genre-filter');
    genre.value = state.genre;
    genre.addEventListener('change', (event) => { state.genre = event.target.value; render(data); });
}

fetch('/data/movies.json')
    .then((response) => response.ok ? response.json() : Promise.reject(new Error('ランキングデータを読み込めませんでした。')))
    .then(render)
    .catch((error) => { app.innerHTML = `<p class="static-error">${escapeHtml(error.message)}</p>`; });

import '../css/cinematic.css';
import '../css/movie-modal.css';
import '../css/static-site.css';

const app = document.querySelector('#app');
const state = { tab: 'global', query: '', category: 'all', genre: '' };

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

const formatReleaseDate = (releaseDate) => {
    if (!releaseDate) return '-';
    const date = new Date(releaseDate);
    if (Number.isNaN(date.getTime())) return releaseDate;
    return `${date.getFullYear()}年${date.getMonth() + 1}月${date.getDate()}日`;
};

function movieCard(movie) {
    const url = posterUrl(movie.posterUrl);
    const subtitle = movie.originalTitle && movie.originalTitle !== movie.title
        ? `<div class="movie-title-en">${escapeHtml(movie.originalTitle)}</div>` : '';
    const analysis = movie.analysis ? `<p class="static-analysis">${escapeHtml(movie.analysis)}</p>` : '';
    const bgImage = url ? `<div class="card-bg-image" style="background-image:url('${escapeHtml(url)}')"></div>` : '';
    return `<article class="top-card rank-${movie.rank} ${movie.isActive ? 'active-movie' : ''}" data-movie-id="${escapeHtml(movie.id)}" role="button" tabindex="0" aria-label="${escapeHtml(movie.title)}の詳細を見る">
        ${movie.isActive ? '<div class="active-badge-card">公開中</div>' : ''}
        <div class="rank-badge">${movie.rank}</div>
        <div class="card-poster ${url ? '' : 'poster-placeholder'}" ${posterStyle(movie.posterUrl)}></div>
        ${bgImage}
        <h2 class="movie-title title-short">${escapeHtml(movie.title)}</h2>
        ${subtitle}
        <div class="revenue-container"><div class="revenue-main">${escapeHtml(movie.revenue)}</div>${movie.revenueYen ? `<div class="revenue-sub">${escapeHtml(movie.revenueYen)}</div>` : ''}</div>
        ${analysis}
    </article>`;
}

function movieRow(movie, isJapan) {
    const url = posterUrl(movie.posterUrl);
    const subtitle = movie.originalTitle && movie.originalTitle !== movie.title
        ? `<span class="movie-title-en">${escapeHtml(movie.originalTitle)}</span>` : '';
    const analysis = movie.analysis ? `<span class="static-analysis static-analysis-row">${escapeHtml(movie.analysis)}</span>` : '';
    const bgImage = url ? `<div class="list-bg-image" style="background-image:url('${escapeHtml(url)}')"></div>` : '';
    return `<article class="list-item ${movie.isActive ? 'active-movie' : ''} ${isJapan ? 'is-japan' : ''}" data-movie-id="${escapeHtml(movie.id)}" role="button" tabindex="0" aria-label="${escapeHtml(movie.title)}の詳細を見る">
        ${movie.isActive ? '<div class="active-badge-card">公開中</div>' : ''}
        ${bgImage}
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

function findMovie(data, movieId) {
    return data[state.tab].find((movie) => movie.id === movieId)
        || [...data.global, ...data.japan].find((movie) => movie.id === movieId)
        || null;
}

function ensureModal() {
    if (document.getElementById('movieModal')) return;

    document.body.insertAdjacentHTML('beforeend', `<div id="movieModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
            <button type="button" class="modal-close" aria-label="閉じる">&times;</button>
            <div id="modalBody" class="modal-body">
                <div class="modal-header">
                    <div class="modal-poster-wrapper">
                        <img id="modalPoster" src="" alt="" class="modal-poster">
                    </div>
                    <div class="modal-title-section">
                        <h2 id="modalTitle" class="modal-title"></h2>
                        <div id="modalTitleEn" class="modal-title-en"></div>
                        <div class="modal-meta">
                            <span id="modalYear" class="meta-tag year"></span>
                        </div>
                        <div id="modalGenres" class="genre-tags"></div>
                    </div>
                </div>
                <div class="modal-grid">
                    <div class="modal-section main-info">
                        <div id="modalAnalysisBlock">
                            <h3>AI分析</h3>
                            <p id="modalAnalysis" class="modal-text"></p>
                        </div>
                    </div>
                    <div class="modal-section side-info">
                        <div class="info-item">
                            <span class="label">興行収入</span>
                            <span class="value" id="modalRevenue"></span>
                        </div>
                        <div class="info-item">
                            <span class="label">公開日</span>
                            <span class="value" id="modalReleaseDate"></span>
                        </div>
                        <div class="info-item" id="modalSourceBlock">
                            <span class="label">詳細</span>
                            <span class="value"><a id="modalSourceLink" href="#" target="_blank" rel="noreferrer">TMDbで見る</a></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>`);

    const modal = document.getElementById('movieModal');
    modal.querySelector('.modal-close').addEventListener('click', () => closeModal());
    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('active')) closeModal();
    });
}

function openModal(movie) {
    ensureModal();
    const modal = document.getElementById('movieModal');
    const poster = posterUrl(movie.posterUrl);
    const year = movie.releaseDate ? movie.releaseDate.slice(0, 4) : '-';

    document.getElementById('modalPoster').src = poster;
    document.getElementById('modalPoster').alt = movie.title;
    document.getElementById('modalTitle').textContent = movie.title;

    const titleEn = document.getElementById('modalTitleEn');
    if (movie.originalTitle && movie.originalTitle !== movie.title) {
        titleEn.textContent = movie.originalTitle;
        titleEn.style.display = 'block';
    } else {
        titleEn.textContent = '';
        titleEn.style.display = 'none';
    }

    document.getElementById('modalYear').textContent = year;
    document.getElementById('modalGenres').innerHTML = (movie.genres || [])
        .map((genre) => `<span class="genre-tag">${escapeHtml(genre)}</span>`)
        .join('');

    const revenueText = movie.revenueYen ? `${movie.revenue} / ${movie.revenueYen}` : movie.revenue;
    document.getElementById('modalRevenue').textContent = revenueText;
    document.getElementById('modalReleaseDate').textContent = formatReleaseDate(movie.releaseDate);

    const analysisBlock = document.getElementById('modalAnalysisBlock');
    const analysis = document.getElementById('modalAnalysis');
    if (movie.analysis) {
        analysis.textContent = movie.analysis;
        analysisBlock.style.display = 'block';
    } else {
        analysis.textContent = '';
        analysisBlock.style.display = 'none';
    }

    const sourceBlock = document.getElementById('modalSourceBlock');
    const sourceLink = document.getElementById('modalSourceLink');
    if (movie.sourceUrl) {
        sourceLink.href = movie.sourceUrl;
        sourceBlock.style.display = 'block';
    } else {
        sourceBlock.style.display = 'none';
    }

    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    const modal = document.getElementById('movieModal');
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

function bindMovieInteractions(data) {
    document.querySelectorAll('[data-movie-id]').forEach((element) => {
        const open = () => {
            const movie = findMovie(data, element.dataset.movieId);
            if (movie) openModal(movie);
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

function render(data) {
    const allGenres = [...new Set([...data.global, ...data.japan].flatMap((movie) => movie.genres || []))]
        .filter((genre) => genre !== 'アニメーション').sort((a, b) => a.localeCompare(b, 'ja'));
    const movies = filterMovies(data[state.tab]);
    const top = movies.filter((movie) => movie.rank <= 3);
    const rest = movies.filter((movie) => movie.rank > 3);
    const isJapan = state.tab === 'japan';
    const title = isJapan ? '日本興行収入ランキング' : '世界興行収入ランキング';

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
      ${movies.length ? `<section class="top-rankings">${top.map(movieCard).join('')}</section><section class="ranking-list">${rest.map((movie) => movieRow(movie, isJapan)).join('')}</section>` : '<p class="static-empty">条件に一致する映画はありません。</p>'}
      <p class="static-updated">最終更新: ${new Date(data.generatedAt).toLocaleString('ja-JP', { timeZone: 'Asia/Tokyo' })}</p>
    </main>
    <footer><div class="container"><p>&copy; ${new Date().getFullYear()} MUBIRAN.</p><p>Data provided by <a href="https://www.themoviedb.org/" target="_blank" rel="noreferrer">TMDb</a> and <a href="https://ja.wikipedia.org/" target="_blank" rel="noreferrer">Wikipedia</a>.</p></div></footer>`;

    document.querySelectorAll('[data-tab]').forEach((button) => button.addEventListener('click', () => {
        state.tab = button.dataset.tab;
        render(data);
    }));
    document.querySelector('#movie-search').addEventListener('input', (event) => {
        state.query = event.target.value;
        render(data);
    });
    const category = document.querySelector('#category-filter');
    category.value = state.category;
    category.addEventListener('change', (event) => {
        state.category = event.target.value;
        render(data);
    });
    const genre = document.querySelector('#genre-filter');
    genre.value = state.genre;
    genre.addEventListener('change', (event) => {
        state.genre = event.target.value;
        render(data);
    });

    bindMovieInteractions(data);
}

fetch('/data/movies.json')
    .then((response) => response.ok ? response.json() : Promise.reject(new Error('ランキングデータを読み込めませんでした。')))
    .then(render)
    .catch((error) => { app.innerHTML = `<p class="static-error">${escapeHtml(error.message)}</p>`; });

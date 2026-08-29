const IMAGE_BASE_URL = 'https://image.tmdb.org/t/p/w200';
const IMAGE_BASE_URL_LARGE = 'https://image.tmdb.org/t/p/w500';

const COUNTRY_MAP = {
    'United States of America': 'アメリカ合衆国',
    USA: 'アメリカ',
    'United Kingdom': 'イギリス',
    UK: 'イギリス',
    Japan: '日本',
    France: 'フランス',
    Germany: 'ドイツ',
    'South Korea': '韓国',
    China: '中国',
    Canada: 'カナダ',
    Italy: 'イタリア',
    Spain: 'スペイン',
    Australia: 'オーストラリア',
    'New Zealand': 'ニュージーランド',
    India: 'インド',
    Mexico: 'メキシコ',
    Brazil: 'ブラジル',
};

const COMPANY_MAP = {
    TOHO: '東宝',
    Toho: '東宝',
    'Toho Pictures': '東宝ピクチャーズ',
    'TOHO Studios': '東宝スタジオ',
    'Toei Company': '東映',
    'Toei Animation': '東映アニメーション',
    Shochiku: '松竹',
    'Nikkatsu Studio': '日活',
    'Daiei Co. Ltd.': '大映',
    KADOKAWA: 'KADOKAWA',
    Kadokawa: 'KADOKAWA',
    'KADOKAWA Daiei Studio': 'KADOKAWA大映スタジオ',
    'Studio Ghibli': 'スタジオジブリ',
    'CoMix Wave Films': 'コミックス・ウェーブ・フィルム',
    MAPPA: 'MAPPA',
    ufotable: 'ufotable',
    'WIT STUDIO': 'WIT STUDIO',
    'Production I.G': 'プロダクション・アイジー',
    Bones: 'ボンズ',
    Madhouse: 'マッドハウス',
    'Kyoto Animation': '京都アニメーション',
    SUNRISE: 'サンライズ',
    Sunrise: 'サンライズ',
    'Shin-Ei Animation': 'シンエイ動画',
    'TMS Entertainment': 'トムス・エンタテインメント',
    'Fuji Television Network': 'フジテレビジョン',
    'Nippon Television Network Corporation': '日本テレビ',
    TBS: 'TBS',
    'TV Asahi': 'テレビ朝日',
    'TV Tokyo': 'テレビ東京',
    dentsu: '電通',
    Hakuhodo: '博報堂',
    Shogakukan: '小学館',
    Shueisha: '集英社',
    Kodansha: '講談社',
    Nintendo: '任天堂',
    Bandai: 'バンダイ',
    'Bandai Namco Entertainment': 'バンダイナムコエンターテインメント',
    Aniplex: 'アニプレックス',
    'Sony Music Entertainment (Japan)': 'ソニー・ミュージックエンタテインメント',
    'avex pictures': 'エイベックス・ピクチャーズ',
    'Pony Canyon': 'ポニーキャニオン',
    'Warner Bros. Japan': 'ワーナー・ブラザース・ジャパン',
    'Walt Disney Japan': 'ウォルト・ディズニー・ジャパン',
    'The Walt Disney Company (Japan)': 'ウォルト・ディズニー・ジャパン',
};

const detailCache = new Map();

function formatReleaseDate(releaseDate) {
    if (!releaseDate) return '-';
    const date = new Date(releaseDate);
    if (Number.isNaN(date.getTime())) return releaseDate;
    return `${date.getFullYear()}年${date.getMonth() + 1}月${date.getDate()}日`;
}

function toYen(usd) {
    if (!usd || usd === 0) return '-';
    return `${((usd * usdJpy) / 100000000).toFixed(1)}億円`;
}

let usdJpy = 150;

export function setUsdJpy(rate) {
    const value = Number(rate);
    if (Number.isFinite(value) && value > 0) {
        usdJpy = value;
    }
}

function ensureModal() {
    if (document.getElementById('movieModal')) return;

    document.body.insertAdjacentHTML('beforeend', `<div id="movieModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
            <button type="button" class="modal-close" aria-label="閉じる">&times;</button>
            <div id="modalLoading" class="modal-loading">
                <div class="spinner"></div>
                <p>情報を取得中...</p>
            </div>
            <div id="modalBody" class="modal-body" style="display: none;">
                <div class="modal-header">
                    <div class="modal-poster-wrapper">
                        <img id="modalPoster" src="" alt="" class="modal-poster">
                    </div>
                    <div class="modal-title-section">
                        <h2 id="modalTitle" class="modal-title"></h2>
                        <div id="modalTitleEn" class="modal-title-en" style="display: none;"></div>
                        <div class="modal-meta">
                            <span id="modalYear" class="meta-tag year"></span>
                            <span id="modalRuntime" class="meta-tag runtime"></span>
                            <span id="modalRating" class="meta-tag rating"></span>
                        </div>
                        <p id="modalTagline" class="modal-tagline"></p>
                    </div>
                </div>
                <div class="modal-grid">
                    <div class="modal-section main-info">
                        <h3>あらすじ</h3>
                        <p id="modalOverview" class="modal-text"></p>
                        <div class="info-row">
                            <div class="info-group">
                                <h4>監督</h4>
                                <p id="modalDirector">-</p>
                            </div>
                            <div class="info-group">
                                <h4>ジャンル</h4>
                                <div id="modalGenres" class="genre-tags"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-section side-info">
                        <div class="info-item">
                            <span class="label">制作国</span>
                            <span class="value" id="modalCountry">-</span>
                        </div>
                        <div class="info-item">
                            <span class="label">予算</span>
                            <span class="value" id="modalBudget">-</span>
                        </div>
                        <div class="info-item">
                            <span class="label">興行収入</span>
                            <span class="value" id="modalRevenue">-</span>
                        </div>
                        <div class="info-item">
                            <span class="label">制作会社</span>
                            <span class="value" id="modalCompany">-</span>
                        </div>
                        <div class="info-item">
                            <span class="label">公開日</span>
                            <span class="value" id="modalReleaseDate">-</span>
                        </div>
                    </div>
                </div>
                <div class="modal-cast-section">
                    <h3>主要キャスト</h3>
                    <div id="modalCast" class="cast-grid"></div>
                </div>
                <p class="modal-history-wrap">
                    <a id="modalHistoryLink" class="modal-history-link" href="#">興収の推移を見る</a>
                </p>
            </div>
        </div>
    </div>`);

    const modal = document.getElementById('movieModal');
    modal.querySelector('.modal-close').addEventListener('click', () => closeMovieModal());
    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeMovieModal();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('active')) closeMovieModal();
    });
}

function populateModal(detail, movie) {
    const modalBody = document.getElementById('modalBody');
    const modalLoading = document.getElementById('modalLoading');
    const posterPath = detail.poster_path
        ? IMAGE_BASE_URL_LARGE + detail.poster_path
        : (movie.posterUrl || '');

    document.getElementById('modalTitle').textContent = detail.title || movie.title;

    const titleEnEl = document.getElementById('modalTitleEn');
    const isJapanese = (detail.production_countries || []).some(
        (country) => country.iso_3166_1 === 'JP' || country.name === 'Japan',
    ) || movie.productionCountry === '日本' || movie.productionCountry === 'JP';
    const originalTitle = detail.original_title || movie.originalTitle;

    if (originalTitle && originalTitle !== (detail.title || movie.title) && !isJapanese) {
        titleEnEl.textContent = originalTitle;
        titleEnEl.style.display = 'block';
    } else {
        titleEnEl.textContent = '';
        titleEnEl.style.display = 'none';
    }

    document.getElementById('modalTagline').textContent = detail.tagline || '';
    document.getElementById('modalOverview').textContent = detail.overview || 'あらすじ情報は現在ありません。';
    document.getElementById('modalPoster').src = posterPath;
    document.getElementById('modalPoster').alt = detail.title || movie.title;

    const releaseDate = detail.release_date || movie.releaseDate;
    document.getElementById('modalYear').textContent = releaseDate ? releaseDate.slice(0, 4) : '-';
    document.getElementById('modalRuntime').textContent = detail.runtime ? `${detail.runtime}分` : '-';
    document.getElementById('modalRating').textContent = `★ ${detail.vote_average ? detail.vote_average.toFixed(1) : '-'}`;

    document.getElementById('modalBudget').textContent = toYen(detail.budget);
    if (movie.revenueBillion) {
        document.getElementById('modalRevenue').textContent = `${movie.revenueBillion}億円`;
    } else {
        document.getElementById('modalRevenue').textContent = toYen(detail.revenue);
    }

    const countryNames = (detail.production_countries || [])
        .map((country) => COUNTRY_MAP[country.name] || country.name)
        .join('、');
    document.getElementById('modalCountry').textContent = countryNames || movie.productionCountry || '-';

    const companyNames = (detail.production_companies || [])
        .slice(0, 2)
        .map((company) => COMPANY_MAP[company.name] || company.name)
        .join('<br>');
    document.getElementById('modalCompany').innerHTML = companyNames || '-';
    document.getElementById('modalReleaseDate').textContent = formatReleaseDate(releaseDate);

    const director = (detail.credits?.crew || []).find((member) => member.job === 'Director');
    document.getElementById('modalDirector').textContent = director ? director.name : '-';

    const genresContainer = document.getElementById('modalGenres');
    const genres = detail.genres?.length ? detail.genres : (movie.genres || []).map((name) => ({ name }));
    genresContainer.innerHTML = genres
        .map((genre) => `<span class="genre-tag">${genre.name}</span>`)
        .join('');

    const castContainer = document.getElementById('modalCast');
    castContainer.innerHTML = '';
    (detail.credits?.cast || []).slice(0, 6).forEach((actor) => {
        const item = document.createElement('div');
        item.className = 'cast-item';
        item.innerHTML = `
            <img class="cast-photo" src="${actor.profile_path ? IMAGE_BASE_URL + actor.profile_path : 'https://via.placeholder.com/80x80/333/fff?text=?'}" alt="${actor.name}">
            <span class="cast-name">${actor.name}</span>
        `;
        castContainer.appendChild(item);
    });

    const historyLink = document.getElementById('modalHistoryLink');
    const slug = movie.slug || movie.id || movie.key;
    if (historyLink && slug) {
        historyLink.href = `/movies/${encodeURIComponent(slug)}/`;
        historyLink.hidden = false;
    } else if (historyLink) {
        historyLink.hidden = true;
    }

    modalLoading.style.display = 'none';
    modalBody.style.display = 'block';
}

function showModalError(message) {
    const modalLoading = document.getElementById('modalLoading');
    modalLoading.style.display = 'flex';
    modalLoading.innerHTML = `<p style="color: #ff6b6b;">${message}</p>`;
    document.getElementById('modalBody').style.display = 'none';
}

async function fetchMovieDetail(movie) {
    if (detailCache.has(movie.id)) {
        return detailCache.get(movie.id);
    }

    const response = await fetch(`/data/details/${encodeURIComponent(movie.id)}.json`);
    if (!response.ok) {
        throw new Error('詳細情報を読み込めませんでした。');
    }

    const detail = await response.json();
    detailCache.set(movie.id, detail);
    return detail;
}

export async function openMovieModal(movie) {
    ensureModal();
    const modal = document.getElementById('movieModal');
    const modalBody = document.getElementById('modalBody');
    const modalLoading = document.getElementById('modalLoading');

    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    modalBody.style.display = 'none';
    modalLoading.style.display = 'flex';
    modalLoading.innerHTML = '<div class="spinner"></div><p>情報を取得中...</p>';

    try {
        const detail = await fetchMovieDetail(movie);
        populateModal(detail, movie);
    } catch (error) {
        showModalError(error.message);
    }
}

export function closeMovieModal() {
    const modal = document.getElementById('movieModal');
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

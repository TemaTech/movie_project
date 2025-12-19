// TMDB Config
const TMDB_BASE_URL = 'https://api.themoviedb.org/3';
const IMAGE_BASE_URL = 'https://image.tmdb.org/t/p/w200';
const IMAGE_BASE_URL_LARGE = 'https://image.tmdb.org/t/p/w500';

// Localization Mapping
const COUNTRY_MAP = {
    'United States of America': 'アメリカ合衆国',
    'USA': 'アメリカ',
    'United Kingdom': 'イギリス',
    'UK': 'イギリス',
    'Japan': '日本',
    'France': 'フランス',
    'Germany': 'ドイツ',
    'South Korea': '韓国',
    'China': '中国',
    'Canada': 'カナダ',
    'Italy': 'イタリア',
    'Spain': 'スペイン',
    'Australia': 'オーストラリア',
    'New Zealand': 'ニュージーランド',
    'India': 'インド',
    'Mexico': 'メキシコ',
    'Brazil': 'ブラジル'
};

// Modal Logic
function getModalElements() {
    return {
        modal: document.getElementById('movieModal'),
        modalBody: document.getElementById('modalBody'),
        modalLoading: document.getElementById('modalLoading')
    };
}

function openModal(title) {
    const { modal, modalBody, modalLoading } = getModalElements();
    if (!modal) {
        console.error('Modal element not found');
        return;
    }
    modal.classList.add('active');
    document.body.style.overflow = 'hidden'; 
    
    if (modalBody) modalBody.style.display = 'none';
    if (modalLoading) modalLoading.style.display = 'flex';
    
    fetchMovieDetails(title);
}

function closeModal(event) {
    const { modal } = getModalElements();
    if (!modal) return;
    if (!event || event.target === modal || event.target.classList.contains('modal-close')) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Close on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const { modal } = getModalElements();
        if (modal && modal.classList.contains('active')) {
            closeModal();
        }
    }
});

async function fetchMovieDetails(title) {
    const apiKey = window.TMDB_API_KEY;
    if (!apiKey) {
        console.error('TMDB_API_KEY is not defined');
        return;
    }
    try {
        // 1. Search for the movie ID
        const searchUrl = `${TMDB_BASE_URL}/search/movie?api_key=${apiKey}&query=${encodeURIComponent(title)}&language=ja-JP`;
        const searchRes = await fetch(searchUrl);
        const searchData = await searchRes.json();

        if (!searchData.results || searchData.results.length === 0) {
            // Handle no results
            showError('映画情報が見つかりませんでした。');
            return;
        }

        const movie = searchData.results[0];
        const movieId = movie.id;

        // 2. Fetch full details including credits and release dates
        const detailsUrl = `${TMDB_BASE_URL}/movie/${movieId}?api_key=${apiKey}&language=ja-JP&append_to_response=credits,keywords,release_dates`;
        const detailRes = await fetch(detailsUrl);
        const data = await detailRes.json();

        populateModal(data);
    } catch (error) {
        console.error('Error fetching details:', error);
        showError('情報の取得中にエラーが発生しました。');
    }
}

function populateModal(data) {
    // Basics
    document.getElementById('modalTitle').textContent = data.title;
    document.getElementById('modalTagline').textContent = data.tagline || '';
    document.getElementById('modalOverview').textContent = data.overview || 'あらすじ情報は現在ありません。';
    document.getElementById('modalPoster').src = data.poster_path ? IMAGE_BASE_URL_LARGE + data.poster_path : '';
    
    // Meta
    document.getElementById('modalYear').textContent = data.release_date ? data.release_date.split('-')[0] : 'Unknown';
    document.getElementById('modalRuntime').textContent = data.runtime ? `${data.runtime}分` : '-';
    document.getElementById('modalRating').textContent = `★ ${data.vote_average ? data.vote_average.toFixed(1) : '-'}`;

    // Extended Info (USD to JPY conversion approx 1 USD = 150 JPY)
    const toYen = (usd) => {
        if (!usd || usd === 0) return '-';
        const yen = usd * 150;
        return (yen / 100000000).toFixed(1) + '億円';
    };

    document.getElementById('modalBudget').textContent = toYen(data.budget);
    document.getElementById('modalRevenue').textContent = toYen(data.revenue);
    
    // Country mapping with fallback
    const countryNames = data.production_countries ? 
        data.production_countries.map(c => COUNTRY_MAP[c.name] || c.name).join('、') : '-';
    document.getElementById('modalCountry').textContent = countryNames || '-';

    const companyNames = data.production_companies ? 
        data.production_companies.slice(0, 2).map(c => c.name).join('、') : '-';
    document.getElementById('modalCompany').textContent = companyNames || '-';

    // Japanese Date Format
    if (data.release_date) {
        const date = new Date(data.release_date);
        document.getElementById('modalReleaseDate').textContent = `${date.getFullYear()}年${date.getMonth() + 1}月${date.getDate()}日`;
    } else {
        document.getElementById('modalReleaseDate').textContent = '-';
    }

    // Director
    const director = data.credits.crew.find(c => c.job === 'Director');
    document.getElementById('modalDirector').textContent = director ? director.name : '-';

    // Genres
    const genresContainer = document.getElementById('modalGenres');
    genresContainer.innerHTML = '';
    if (data.genres) {
        data.genres.forEach(g => {
            const tag = document.createElement('span');
            tag.className = 'genre-tag';
            tag.textContent = g.name;
            genresContainer.appendChild(tag);
        });
    }

    // Cast (Top 6)
    const castContainer = document.getElementById('modalCast');
    castContainer.innerHTML = '';
    if (data.credits.cast) {
        data.credits.cast.slice(0, 6).forEach(actor => {
            const item = document.createElement('div');
            item.className = 'cast-item';
            
            const img = document.createElement('img');
            img.className = 'cast-photo';
            img.src = actor.profile_path ? IMAGE_BASE_URL + actor.profile_path : 'https://via.placeholder.com/80x80/333/fff?text=?';
            img.alt = actor.name;

            const name = document.createElement('span');
            name.className = 'cast-name';
            name.textContent = actor.name;

            const char = document.createElement('span');
            char.className = 'cast-character';
            char.textContent = actor.character;

            item.appendChild(img);
            item.appendChild(name);
            item.appendChild(char);
            castContainer.appendChild(item);
        });
    }

    // Show Content
    modalLoading.style.display = 'none';
    modalBody.style.display = 'block';
}

function showError(msg) {
    modalLoading.innerHTML = `<p style="color: #ff6b6b;">${msg}</p>`;
}

async function fetchImages() {
    const posters = document.querySelectorAll('.tmdb-poster');
    
    for (const poster of posters) {
        const title = poster.dataset.title;
        if (!title) continue;

        // Check Cache
        const cachedImage = localStorage.getItem('tmdb_poster_' + title);
        if (cachedImage) {
            poster.style.backgroundImage = `url('${cachedImage}')`;
            continue;
        }

        try {
            const apiKey = window.TMDB_API_KEY;
            if (!apiKey) continue;

            // Search Movie
            const searchUrl = `${TMDB_BASE_URL}/search/movie?api_key=${apiKey}&query=${encodeURIComponent(title)}&language=ja-JP`;
            const response = await fetch(searchUrl);
            const data = await response.json();

            if (data.results && data.results.length > 0) {
                const posterPath = data.results[0].poster_path;
                if (posterPath) {
                    const imageUrl = poster.classList.contains('poster-placeholder') ? 
                        IMAGE_BASE_URL_LARGE + posterPath : 
                        IMAGE_BASE_URL + posterPath;
                    
                    poster.style.backgroundImage = `url('${imageUrl}')`;
                    
                    // Cache it
                    localStorage.setItem('tmdb_poster_' + title, imageUrl);
                }
            }
        } catch (error) {
            console.error('Error fetching image for:', title, error);
        }
    }
}

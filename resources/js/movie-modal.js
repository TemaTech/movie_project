// TMDB Config
const TMDB_BASE_URL = 'https://api.themoviedb.org/3';
const IMAGE_BASE_URL = 'https://image.tmdb.org/t/p/w200';
const IMAGE_BASE_URL_LARGE = 'https://image.tmdb.org/t/p/w500';

// Cache Configuration
const CACHE_PREFIX_POSTER = 'tmdb_poster_id_';
const CACHE_PREFIX_DETAIL = 'tmdb_detail_id_';
const LEGACY_CACHE_PREFIX = 'tmdb_poster_';

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

// 制作会社の日本語変換マップ（日本映画1〜200位の日本企業を網羅）
const COMPANY_MAP = {
    // ===== 映画会社・スタジオ =====
    'TOHO': '東宝',
    'Toho': '東宝',
    'Toho Pictures': '東宝ピクチャーズ',
    'TOHO Studios': '東宝スタジオ',
    'Toho Eizo Bijutsu': '東宝映像美術',
    'Toei Company': '東映',
    'Toei Animation': '東映アニメーション',
    'Shochiku': '松竹',
    'Nikkatsu Studio': '日活',
    'Daiei Co. Ltd.': '大映',
    'Daiei TV-Film': '大映テレビ',
    'KADOKAWA': 'KADOKAWA',
    'Kadokawa': 'KADOKAWA',
    'KADOKAWA Daiei Studio': 'KADOKAWA大映スタジオ',
    
    // ===== アニメスタジオ =====
    'Studio Ghibli': 'スタジオジブリ',
    'Nibariki': '二馬力',
    'Studio Chizu': 'スタジオ地図',
    'CoMix Wave Films': 'コミックス・ウェーブ・フィルム',
    'MAPPA': 'MAPPA',
    'ufotable': 'ufotable',
    'WIT STUDIO': 'WIT STUDIO',
    'Production I.G': 'プロダクション・アイジー',
    'Bones': 'ボンズ',
    'Madhouse': 'マッドハウス',
    'Kyoto Animation': '京都アニメーション',
    'SUNRISE': 'サンライズ',
    'Sunrise': 'サンライズ',
    'Shin-Ei Animation': 'シンエイ動画',
    'TMS Entertainment': 'トムス・エンタテインメント',
    'OLM': 'OLM',
    'Pierrot': 'ぴえろ',
    'CloverWorks': 'CloverWorks',
    'khara': 'カラー',
    'Bandai Namco Filmworks': 'バンダイナムコフィルムワークス',
    'DandeLion Animation Studio': 'ダンデライオン・アニメーションスタジオ',
    'Shirogumi': '白組',
    'Yasahi-Mation Productions': 'ヤサヒメーションプロダクション',
    'Myriagon Studio': 'ミリアゴンスタジオ',
    
    // ===== テレビ局 =====
    'Fuji Television Network': 'フジテレビジョン',
    'Nippon Television Network Corporation': '日本テレビ',
    'TBS': 'TBS',
    'TBS Radio': 'TBSラジオ',
    'TBS Sparkle': 'TBSスパークル',
    'TV Asahi': 'テレビ朝日',
    'TV Tokyo': 'テレビ東京',
    'TV Osaka': 'テレビ大阪',
    'TV Setouchi Broadcasting': 'テレビせとうち',
    'TVQ Kyushu Broadcasting': 'TVQ九州放送',
    'Yomiuri Telecasting Corporation': '読売テレビ',
    'MBS': 'MBS',
    'Kansai Television': '関西テレビ',
    'Asahi Broadcasting Corporation': '朝日放送',
    'Tokai Television Broadcasting': '東海テレビ',
    'Nagoya Broadcasting Network': '名古屋テレビ',
    'Chukyo TV Broadcasting Company': '中京テレビ',
    'CBC': 'CBC',
    'Hokkaido Broadcasting': '北海道放送',
    'Hokkaido Television Broadcasting': '北海道テレビ',
    'Hokkaido Cultural Broadcasting': '北海道文化放送',
    'Television Hokkaido Broadcasting': 'テレビ北海道',
    'Sapporo Television Broadcasting Company': '札幌テレビ',
    'RKB Mainichi Broadcasting Corporation': 'RKB毎日放送',
    'Kyushu Asahi Broadcasting': '九州朝日放送',
    'Television Nishinippon Corporation': 'テレビ西日本',
    'Hiroshima Home Television': '広島ホームテレビ',
    'Hiroshima Telecasting': '広島テレビ',
    'Television Shin-Hiroshima System': 'テレビ新広島',
    'Niigata Sogo Television': 'NST新潟総合テレビ',
    'Sendai Television': '仙台放送',
    'Miyagi Television Broadcasting': '宮城テレビ',
    'Fukuoka Broadcasting System': '福岡放送',
    'Iwate Menkoi Television': 'めんこいテレビ',
    'Akita Television': '秋田テレビ',
    'Fukushima Television Broadcasting': '福島テレビ',
    'Nagano Broadcasting System': '長野放送',
    'Ishikawa Television Broadcasting': '石川テレビ',
    'Toyama Television': '富山テレビ',
    'Fukui Television Broadcasting': '福井テレビ',
    'San-in Chuo Television Broadcasting': '山陰中央テレビ',
    'Okayama Broadcasting': '岡山放送',
    'Ehime Broadcasting': '愛媛放送',
    'Kochi Sun Sun Broadcasting': '高知さんさんテレビ',
    'Shizuoka Telecasting': '静岡朝日テレビ',
    'Shizuoka Daiichi Television': '静岡第一テレビ',
    'Television Nagasaki': 'テレビ長崎',
    'Television Oita System': 'テレビ大分',
    'Kumamoto Kenmin Televisions': '熊本県民テレビ',
    'Saga Television Station': 'サガテレビ',
    'Kagoshima Television Station': '鹿児島テレビ',
    'Miyazaki Telecasting': 'テレビ宮崎',
    'Okinawa Television Broadcasting': '沖縄テレビ',
    'Sakuranbo Television Broadcasting Corporation': 'さくらんぼテレビ',
    'Kids Station': 'キッズステーション',
    'Tokyo FM': 'TOKYO FM',
    'AX-ON': 'AX-ON',
    
    // ===== 広告代理店・マーケティング =====
    'dentsu': '電通',
    'dentsu Music and Entertainment': '電通ミュージック・アンド・エンタテインメント',
    'Hakuhodo': '博報堂',
    'Hakuhodo DY Media Partners': '博報堂DYメディアパートナーズ',
    'ADK': 'ADK',
    'ADK Emotions': 'ADKエモーションズ',
    'jeki': 'ジェイアール東日本企画',
    'Metro Ad Agency': 'メトロアドエージェンシー',
    'Tokyu Agency': '東急エージェンシー',
    
    // ===== 出版社・メディア =====
    'Shogakukan': '小学館',
    'Shueisha': '集英社',
    'Shogakukan-Shueisha Productions': '小学館集英社プロダクション',
    'Shogakukan Production': '小学館プロダクション',
    'Kodansha': '講談社',
    'KADOKAWA': 'KADOKAWA',
    'Tokuma Shoten': '徳間書店',
    'Futabasha': '双葉社',
    'Ohta Publishing': '太田出版',
    'Media Factory': 'メディアファクトリー',
    'Enterbrain': 'エンターブレイン',
    'BookWalker': 'BOOK☆WALKER',
    'The Asahi Shimbun': '朝日新聞社',
    'Yomiuri Shimbun Company': '読売新聞社',
    'Chunichi Shimbun': '中日新聞社',
    'THE NISHINIPPON SHIMBUN': '西日本新聞社',
    'NIKKEI': '日本経済新聞社',
    
    // ===== 音楽・エンタメ企業 =====
    'Sony Music Entertainment (Japan)': 'ソニー・ミュージックエンタテインメント',
    'avex pictures': 'エイベックス・ピクチャーズ',
    'Pony Canyon': 'ポニーキャニオン',
    'Aniplex': 'アニプレックス',
    'J Storm': 'ジェイ・ストーム',
    'AMUSE': 'アミューズ',
    'Amuse Soft Entertainment': 'アミューズソフトエンタテインメント',
    'Horipro': 'ホリプロ',
    'Stardust Pictures': 'スターダストピクチャーズ',
    'LesPros Entertainment': 'レプロエンタテインメント',
    'Watanabe Entertainment': '渡辺エンターテインメント',
    'TEN CARAT': 'テンカラット',
    
    // ===== ゲーム・玩具 =====
    'Nintendo': '任天堂',
    'Bandai': 'バンダイ',
    'Bandai Namco Entertainment': 'バンダイナムコエンターテインメント',
    'Tomy': 'タカラトミー',
    'GAME FREAK': 'ゲームフリーク',
    'Creatures': 'クリーチャーズ',
    'LEVEL5': 'レベルファイブ',
    'Pikachu Project \'98': 'ピカチュウプロジェクト',
    
    // ===== 通信・IT =====
    'NTT Docomo': 'NTTドコモ',
    'KDDI': 'KDDI',
    'LY Corporation': 'LINEヤフー',
    'Yahoo! Japan': 'Yahoo! JAPAN',
    'GYAO': 'GYAO',
    
    // ===== プロダクション・制作会社 =====
    'Robot Communications': 'ロボットコミュニケーションズ',
    'AOI Pro.': 'AOI Pro.',
    'IMAGICA': 'IMAGICA',
    'TC Entertainment': 'TCエンタテインメント',
    'TC Productions': 'TCプロダクション',
    'Sedic': 'セディックインターナショナル',
    'Film Makers': 'フィルムメーカーズ',
    'V1 studio': 'V1 studio',
    'BIGFACE': 'BIGFACE',
    'CREDEUS': 'クレデウス',
    'Cross Media': 'クロスメディア',
    'Digital Image Associates': 'デジタル・イメージ・アソシエイツ',
    'Odin': 'オディン',
    'INP': 'INP',
    'Suirinsha': '水輪社',
    'Cine Bazar': 'シネバザール',
    'GNDHDDT': 'GNDHDDT',
    'Kurahara Productions': '蔵原プロダクション',
    'Office Nino': 'オフィスニノ',
    'Shuji Abe Office': '阿部秀司事務所',
    'K-Factory': 'K-Factory',
    'J-dream': 'Jドリーム',
    'Funimation Global Group': 'ファニメーション',
    
    // ===== その他の日本企業 =====
    'Lawson': 'ローソン',
    'Lawson Entertainment': 'ローソンエンタテインメント',
    'Mitsubishi': '三菱商事',
    'Mitsubishi Shoji': '三菱商事',
    'Tohan': 'トーハン',
    'Nippan Group Holdings': '日販グループホールディングス',
    'Pioneer': 'パイオニア',
    'Gakken Co. Ltd.': '学研',
    'd-rights': 'ディーライツ',
    'movic': 'ムービック',
    'voque ting': 'ヴォーク・ティング',
    'Sumzap': 'サムザップ',
    'Story': 'ストーリー',
    'MIRACLE Productions': 'ミラクルプロダクション',
    'TFC': 'TFC',
    'TNDG': 'TNDG',
    'NPV Entertainment': 'NPVエンターテインメント',
    'Audio Planning U': 'オーディオプランニングユー',
    'Fujiko Productions': 'フジコ・プロダクション',
    
    // ===== 製作委員会・特別プロジェクト =====
    'Meitantei Conan Production Committee': '名探偵コナン製作委員会',
    '"Weathering With You" Film Partners': '「天気の子」製作委員会',
    
    // ===== 海外日本法人 =====
    'Warner Bros. Japan': 'ワーナー・ブラザース・ジャパン',
    'Walt Disney Japan': 'ウォルト・ディズニー・ジャパン',
    'The Walt Disney Company (Japan)': 'ウォルト・ディズニー・ジャパン',
    'Sony Pictures International Productions': 'ソニー・ピクチャーズ・インターナショナル・プロダクションズ'
};

// Modal Logic
function getModalElements() {
    return {
        modal: document.getElementById('movieModal'),
        modalBody: document.getElementById('modalBody'),
        modalLoading: document.getElementById('modalLoading')
    };
}

function openModal(title, movieId = null, releaseYear = null, tmdbId = null, revenue = null) {
    const { modal, modalBody, modalLoading } = getModalElements();
    if (!modal) {
        console.error('Modal element not found');
        return;
    }
    modal.classList.add('active');
    document.body.style.overflow = 'hidden'; 
    
    if (modalBody) modalBody.style.display = 'none';
    if (modalLoading) modalLoading.style.display = 'flex';
    
    fetchMovieDetails(title, movieId, releaseYear, tmdbId, revenue);
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

async function fetchMovieDetails(title, movieId = null, releaseYear = null, tmdbId = null, revenue = null) {
    const apiKey = window.TMDB_API_KEY;
    if (!apiKey) {
        console.error('TMDB_API_KEY is not defined');
        return;
    }

    const cacheKey = movieId ? CACHE_PREFIX_DETAIL + movieId : null;
    
    // Check Cache
    if (cacheKey) {
        const cachedData = localStorage.getItem(cacheKey);
        if (cachedData) {
            try {
                const data = JSON.parse(cachedData);
                // キャッシュデータのバリデーション（creditsが必須）
                if (!data.credits) {
                    console.warn('Invalid cached data (missing credits), fetching fresh data');
                    localStorage.removeItem(cacheKey);
                } else {
                    populateModal(data, revenue);
                    return;
                }
            } catch (e) {
                console.error('Error parsing cached detail data:', e);
                localStorage.removeItem(cacheKey);
            }
        }
    }

    try {
        let movieData = null;

        // 1. Try to use TMDB ID directly (Most reliable)
        if (tmdbId) {
            const detailsUrl = `${TMDB_BASE_URL}/movie/${tmdbId}?api_key=${apiKey}&language=ja-JP&append_to_response=credits,keywords,release_dates`;
            const detailRes = await fetch(detailsUrl);
            if (detailRes.ok) {
                movieData = await detailRes.json();
            }
        }
        
        // 2. Try to use global TMDB ID from movieId if available
        if (!movieData && movieId && movieId.startsWith('global_')) {
            const parts = movieId.split('_');
            const tId = parts[parts.length - 1];
            if (tId && !isNaN(tId)) {
                const detailsUrl = `${TMDB_BASE_URL}/movie/${tId}?api_key=${apiKey}&language=ja-JP&append_to_response=credits,keywords,release_dates`;
                const detailRes = await fetch(detailsUrl);
                if (detailRes.ok) {
                    movieData = await detailRes.json();
                }
            }
        }

        // 2. Fallback to title search if ID search failed or was not possible
        if (!movieData) {
            // 公開年がある場合は年指定で精度を上げる
            let searchUrl = `${TMDB_BASE_URL}/search/movie?api_key=${apiKey}&query=${encodeURIComponent(title)}&language=ja-JP`;
            if (releaseYear) {
                searchUrl += `&year=${releaseYear}`;
            }
            const searchRes = await fetch(searchUrl);
            const searchData = await searchRes.json();

            let selectedMovie = null;
            if (searchData.results && searchData.results.length > 0) {
                // 公開年が指定されている場合、その年に一致するものを優先
                if (releaseYear) {
                    selectedMovie = searchData.results.find(m => {
                        const movieYear = m.release_date ? m.release_date.split('-')[0] : null;
                        return movieYear === String(releaseYear);
                    });
                }
                // 見つからなければ最初の結果を使用
                if (!selectedMovie) {
                    selectedMovie = searchData.results[0];
                }
            }

            if (!selectedMovie) {
                showError('映画情報が見つかりませんでした。');
                return;
            }

            const detailsUrl = `${TMDB_BASE_URL}/movie/${selectedMovie.id}?api_key=${apiKey}&language=ja-JP&append_to_response=credits,keywords,release_dates`;
            const detailRes = await fetch(detailsUrl);
            movieData = await detailRes.json();
        }

        // Save to Cache
        if (cacheKey && movieData) {
            localStorage.setItem(cacheKey, JSON.stringify(movieData));
        }

        populateModal(movieData, revenue);
    } catch (error) {
        console.error('Error fetching details:', error);
        showError('情報の取得中にエラーが発生しました。');
    }
}

function populateModal(data, revenue = null) {
    // Basics
    document.getElementById('modalTitle').textContent = data.title;
    
    // original_titleの表示ロジック
    const titleEnEl = document.getElementById('modalTitleEn');
    if (titleEnEl) {
        // 日本映画かどうかをチェック (iso_3166_1: 'JP' を探す)
        const isJapanese = data.production_countries && data.production_countries.some(c => c.iso_3166_1 === 'JP' || c.name === 'Japan');
        const originalTitle = data.original_title;
        
        if (originalTitle && originalTitle !== data.title && !isJapanese) {
            titleEnEl.textContent = originalTitle;
            titleEnEl.style.display = 'block';
        } else {
            titleEnEl.style.display = 'none';
        }
    }

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
    if (revenue) {
        document.getElementById('modalRevenue').textContent = revenue + '億円';
    } else {
        document.getElementById('modalRevenue').textContent = toYen(data.revenue);
    }
    
    // Country mapping with fallback
    const countryNames = data.production_countries ? 
        data.production_countries.map(c => COUNTRY_MAP[c.name] || c.name).join('、') : '-';
    document.getElementById('modalCountry').textContent = countryNames || '-';

    const companyNames = data.production_companies ? 
        data.production_companies.slice(0, 2).map(c => COMPANY_MAP[c.name] || c.name).join('<br>') : '-';
    document.getElementById('modalCompany').innerHTML = companyNames || '-';

    // Japanese Date Format
    if (data.release_date) {
        const date = new Date(data.release_date);
        document.getElementById('modalReleaseDate').textContent = `${date.getFullYear()}年${date.getMonth() + 1}月${date.getDate()}日`;
    } else {
        document.getElementById('modalReleaseDate').textContent = '-';
    }

    // Director
    const director = data.credits?.crew?.find(c => c.job === 'Director');
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
    if (data.credits?.cast) {
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

            item.appendChild(img);
            item.appendChild(name);
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

// 背景画像要素を動的に追加するヘルパー関数
// element: .tmdb-poster要素、.list-item要素、または.top-card要素
function addBackgroundImageElement(element, imageUrl) {
    // 対象の親コンテナを特定
    const listItem = element.classList.contains('list-item') ? element : element.closest('.list-item');
    const topCard = element.classList.contains('top-card') ? element : element.closest('.top-card');
    
    if (listItem) {
        // list-itemの場合: list-bg-imageを追加
        if (!listItem.querySelector('.list-bg-image')) {
            const bgImage = document.createElement('div');
            bgImage.className = 'list-bg-image';
            bgImage.style.backgroundImage = `url('${imageUrl}')`;
            // revenue-bar-bgの直後に挿入
            const revenueBar = listItem.querySelector('.revenue-bar-bg');
            if (revenueBar && revenueBar.nextSibling) {
                listItem.insertBefore(bgImage, revenueBar.nextSibling);
            } else {
                listItem.insertBefore(bgImage, listItem.firstChild);
            }
        }
    } else if (topCard) {
        // top-cardの場合: card-bg-imageを追加
        if (!topCard.querySelector('.card-bg-image')) {
            const bgImage = document.createElement('div');
            bgImage.className = 'card-bg-image';
            bgImage.style.backgroundImage = `url('${imageUrl}')`;
            // 最初の子要素として挿入
            topCard.insertBefore(bgImage, topCard.firstChild);
        }
    }
}

// リスト項目の背景画像を処理（サーバーサイドで設定されている場合、またはTMDBから動的取得）
async function processListBackgrounds() {
    const listItems = document.querySelectorAll('.list-item');
    const apiKey = window.TMDB_API_KEY;
    
    for (const item of listItems) {
        // 既に背景画像がある場合はスキップ
        if (item.querySelector('.list-bg-image')) continue;
        
        const posterUrl = item.dataset.posterUrl;
        
        // サーバーサイドで設定済みの場合
        if (posterUrl) {
            addBackgroundImageElement(item, posterUrl);
            continue;
        }
        
        // TMDBから動的に取得する場合
        const tmdbId = item.dataset.tmdbId;
        const movieId = item.dataset.movieId;
        const title = item.dataset.title;
        const releaseYear = item.dataset.releaseYear;
        
        if (!apiKey) continue;
        
        let imageUrl = null;
        
        // キャッシュを確認
        const cacheKey = movieId ? CACHE_PREFIX_POSTER + movieId : null;
        if (cacheKey) {
            const cachedImage = localStorage.getItem(cacheKey);
            if (cachedImage) {
                addBackgroundImageElement(item, cachedImage);
                continue;
            }
        }
        
        try {
            let posterPath = null;
            
            // 1. TMDB IDから取得（最も信頼性が高い）
            if (tmdbId) {
                const res = await fetch(`${TMDB_BASE_URL}/movie/${tmdbId}?api_key=${apiKey}&language=ja-JP`);
                if (res.ok) {
                    const data = await res.json();
                    posterPath = data.poster_path;
                }
            }
            // 2. movie_idからTMDB IDを抽出して取得
            else if (movieId && movieId.startsWith('global_')) {
                const parts = movieId.split('_');
                const tId = parts[parts.length - 1];
                if (tId && !isNaN(tId)) {
                    const res = await fetch(`${TMDB_BASE_URL}/movie/${tId}?api_key=${apiKey}&language=ja-JP`);
                    if (res.ok) {
                        const data = await res.json();
                        posterPath = data.poster_path;
                    }
                }
            }
            // 3. タイトル検索（フォールバック）
            else if (title) {
                let searchUrl = `${TMDB_BASE_URL}/search/movie?api_key=${apiKey}&query=${encodeURIComponent(title)}&language=ja-JP`;
                if (releaseYear) {
                    searchUrl += `&year=${releaseYear}`;
                }
                const res = await fetch(searchUrl);
                if (res.ok) {
                    const data = await res.json();
                    if (data.results && data.results.length > 0) {
                        posterPath = data.results[0].poster_path;
                    }
                }
            }
            
            if (posterPath) {
                imageUrl = IMAGE_BASE_URL_LARGE + posterPath;
                addBackgroundImageElement(item, imageUrl);
                // キャッシュに保存
                if (cacheKey) {
                    localStorage.setItem(cacheKey, imageUrl);
                }
            }
        } catch (error) {
            console.error('Error fetching image for list item:', error);
        }
    }
}


async function fetchImages() {
    const posters = document.querySelectorAll('.tmdb-poster');
    
    // Cleanup legacy title-based cache
    Object.keys(localStorage).forEach(key => {
        if (key.startsWith(LEGACY_CACHE_PREFIX) && !key.startsWith(CACHE_PREFIX_POSTER)) {
            localStorage.removeItem(key);
        }
    });

    for (const poster of posters) {
        const title = poster.dataset.title;
        const movieId = poster.dataset.movieId;
        const tmdbId = poster.dataset.tmdbId; // New: Get TMDB ID directly
        const releaseYear = poster.dataset.releaseYear;
        
        if (!title && !movieId && !tmdbId) continue;

        // If we already have the image set via PHP (server-side), skip fetching (unless we want to cache it?)
        // The server-side rendering sets the style attribute, so we can check that.
        if (poster.style.backgroundImage && poster.style.backgroundImage !== 'none' && !poster.classList.contains('poster-placeholder')) {
            // 既にサーバーサイドで設定されている場合でも、背景画像要素を追加
            const imageUrl = poster.style.backgroundImage.slice(5, -2);
            addBackgroundImageElement(poster, imageUrl);
            continue;
        }

        // Use movieId as cache key if available
        const cacheKey = movieId ? CACHE_PREFIX_POSTER + movieId : (title ? LEGACY_CACHE_PREFIX + title : null);
        if (!cacheKey) continue;
        
        // Check Cache
        const cachedImage = localStorage.getItem(cacheKey);
        if (cachedImage) {
            poster.style.backgroundImage = `url('${cachedImage}')`;
            // キャッシュから読み込み時も背景画像要素を生成
            addBackgroundImageElement(poster, cachedImage);
            continue;
        }

        try {
            const apiKey = window.TMDB_API_KEY;
            if (!apiKey) continue;

            let posterPath = null;

            // 1. Try to fetch by TMDB ID (Most reliable)
            if (tmdbId) {
                const detailsUrl = `${TMDB_BASE_URL}/movie/${tmdbId}?api_key=${apiKey}&language=ja-JP`;
                const res = await fetch(detailsUrl);
                if (res.ok) {
                    const data = await res.json();
                    posterPath = data.poster_path;
                }
            }
            // 2. Try to fetch by generic ID if possible
            else if (movieId && movieId.startsWith('global_')) {
                const parts = movieId.split('_');
                const tId = parts[parts.length - 1];
                if (tId && !isNaN(tId)) {
                    const detailsUrl = `${TMDB_BASE_URL}/movie/${tId}?api_key=${apiKey}&language=ja-JP`;
                    const res = await fetch(detailsUrl);
                    if (res.ok) {
                        const data = await res.json();
                        posterPath = data.poster_path;
                    }
                }
            }

            // 3. Fallback to title search with year filtering
            if (!posterPath && title) {
                let searchUrl = `${TMDB_BASE_URL}/search/movie?api_key=${apiKey}&query=${encodeURIComponent(title)}&language=ja-JP`;
                if (releaseYear) {
                    searchUrl += `&year=${releaseYear}`;
                }
                const response = await fetch(searchUrl);
                const data = await response.json();

                if (data.results && data.results.length > 0) {
                    // 公開年が指定されている場合、その年に一致するものを優先
                    let selectedResult = null;
                    if (releaseYear) {
                        selectedResult = data.results.find(m => {
                            const movieYear = m.release_date ? m.release_date.split('-')[0] : null;
                            return movieYear === String(releaseYear);
                        });
                    }
                    if (!selectedResult) {
                        selectedResult = data.results[0];
                    }
                    posterPath = selectedResult.poster_path;
                }
            }

            if (posterPath) {
                const imageUrl = poster.classList.contains('poster-placeholder') ? 
                    IMAGE_BASE_URL_LARGE + posterPath : 
                    IMAGE_BASE_URL + posterPath;
                
                poster.style.backgroundImage = `url('${imageUrl}')`;
                
                // グラデーション付き背景画像要素を動的に追加
                addBackgroundImageElement(poster, imageUrl);
                // Cache it
                localStorage.setItem(cacheKey, imageUrl);
            }
        } catch (error) {
            console.error('Error fetching image for:', title || movieId, error);
        }
    }
}

// Expose functions to global scope for Blade templates
window.openModal = openModal;
window.closeModal = closeModal;
window.fetchImages = fetchImages;
window.processListBackgrounds = processListBackgrounds;

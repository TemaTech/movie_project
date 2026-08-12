const DECADE_LABELS = {
    '2020s': '2020年代',
    '2010s': '2010年代',
    '2000s': '2000年代',
    older: 'それ以前',
};

const GENRE_MAP = {
    アニメーション: 'アニメ',
    サイエンスフィクション: 'SF',
    アニメ: 'アニメーション',
    SF: 'サイエンスフィクション',
    謎: 'ミステリー',
    ミステリー: '謎',
    犯罪: 'サスペンス',
    サスペンス: '犯罪',
    履歴: '歴史',
    歴史: '履歴',
};

function genreAliases(genre) {
    const aliases = new Set([genre]);
    if (GENRE_MAP[genre]) aliases.add(GENRE_MAP[genre]);
    const reverse = Object.entries(GENRE_MAP).find(([, value]) => value === genre);
    if (reverse) aliases.add(reverse[0]);
    return [...aliases];
}

function movieHasGenre(movie, genre) {
    const aliases = genreAliases(genre);
    return (movie.genres || []).some((movieGenre) => aliases.includes(movieGenre));
}

function escapeHtml(value = '') {
    return String(value).replace(/[&<>'"]/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;',
    })[char]);
}

function matchesYear(movie, year, currentYear) {
    const releaseYear = movie.releaseYear;
    if (!releaseYear) return false;

    if (/^\d{4}$/.test(year)) {
        return releaseYear === Number(year);
    }

    switch (year) {
    case '2020s':
        return releaseYear >= 2020 && releaseYear <= Math.min(currentYear - 3, 2029);
    case '2010s':
        return releaseYear >= 2010 && releaseYear <= 2019;
    case '2000s':
        return releaseYear >= 2000 && releaseYear <= 2009;
    case 'older':
        return releaseYear < 2000;
    default:
        return false;
    }
}

export function filterMovies(movies, filters) {
    const currentYear = new Date().getFullYear();

    return movies.filter((movie) => {
        const categoryMatches = filters.category === 'all'
            || (filters.category === 'anime' && movie.isAnime)
            || (filters.category === 'live' && !movie.isAnime);

        const genreMatches = !filters.genres.length
            || (filters.matchMode === 'and'
                ? filters.genres.every((genre) => movieHasGenre(movie, genre))
                : filters.genres.some((genre) => movieHasGenre(movie, genre)));

        const yearMatches = !filters.years.length
            || filters.years.some((year) => matchesYear(movie, year, currentYear));

        const filterGroups = [genreMatches, yearMatches];
        const advancedMatches = filters.matchMode === 'and'
            ? filterGroups.every(Boolean)
            : filterGroups.some(Boolean) || (!filters.genres.length && !filters.years.length);

        return categoryMatches && advancedMatches;
    });
}

export function activeFilterCount(filters) {
    let count = 0;
    if (filters.category !== 'all') count += 1;
    if (filters.genres.length) count += filters.genres.length;
    if (filters.years.length) count += filters.years.length;
    return count;
}

export function renderFilterModal(genres, filters) {
    const currentYear = new Date().getFullYear();
    const recentYears = [currentYear, currentYear - 1, currentYear - 2];
    const decades = ['2020s', '2010s', '2000s', 'older'];

    return `<div class="filter-modal-overlay" id="filterModalOverlay">
        <div class="filter-modal" id="filterModal">
            <div class="filter-modal-header">
                <h2 class="filter-modal-title">絞り込み検索</h2>
                <button type="button" class="filter-modal-close" id="filterModalClose" aria-label="閉じる">×</button>
            </div>
            <form id="filterForm">
                <div class="filter-section">
                    <h3 class="filter-section-title">カテゴリ</h3>
                    <div class="filter-chips">
                        ${['all', 'live', 'anime'].map((category) => {
                            const labels = { all: 'すべて', live: '実写映画', anime: 'アニメ映画' };
                            const active = filters.category === category ? 'active' : '';
                            return `<label class="filter-chip category-${category} ${active}" data-category="${category}">
                                <input type="radio" name="category" value="${category}" ${filters.category === category ? 'checked' : ''} hidden>
                                ${labels[category]}
                            </label>`;
                        }).join('')}
                    </div>
                </div>
                <div class="filter-section">
                    <h3 class="filter-section-title">ジャンル</h3>
                    <div class="filter-chips" id="genreChips">
                        ${genres.map((genre) => {
                            const active = filters.genres.includes(genre) ? 'active' : '';
                            return `<label class="filter-chip ${active}" data-genre="${escapeHtml(genre)}">
                                <input type="checkbox" value="${escapeHtml(genre)}" ${active ? 'checked' : ''} hidden>
                                ${escapeHtml(genre)}
                            </label>`;
                        }).join('')}
                    </div>
                </div>
                <div class="filter-section">
                    <h3 class="filter-section-title">制作年</h3>
                    <div class="filter-chips" id="yearChips">
                        ${recentYears.map((year) => {
                            const active = filters.years.includes(String(year)) ? 'active' : '';
                            return `<label class="filter-chip ${active}" data-year="${year}">
                                <input type="checkbox" value="${year}" ${active ? 'checked' : ''} hidden>
                                ${year}年
                            </label>`;
                        }).join('')}
                        ${decades.map((decade) => {
                            const active = filters.years.includes(decade) ? 'active' : '';
                            return `<label class="filter-chip ${active}" data-year="${decade}">
                                <input type="checkbox" value="${decade}" ${active ? 'checked' : ''} hidden>
                                ${DECADE_LABELS[decade]}
                            </label>`;
                        }).join('')}
                    </div>
                </div>
                <div class="filter-section">
                    <div class="match-mode-container">
                        <span class="match-mode-label">検索モード:</span>
                        <div class="match-mode-switch">
                            <button type="button" class="match-mode-option ${filters.matchMode === 'and' ? 'active' : ''}" data-mode="and">AND</button>
                            <button type="button" class="match-mode-option ${filters.matchMode === 'or' ? 'active' : ''}" data-mode="or">OR</button>
                        </div>
                        <span class="match-mode-hint" id="matchModeHint">${filters.matchMode === 'or' ? 'いずれかの条件に一致' : 'すべての条件に一致'}</span>
                    </div>
                </div>
                <div class="filter-actions">
                    <button type="button" class="filter-btn filter-btn-reset" id="filterReset">リセット</button>
                    <button type="submit" class="filter-btn filter-btn-apply">絞り込む</button>
                </div>
            </form>
        </div>
    </div>`;
}

export function bindFilterModal(filters, onApply) {
    const overlay = document.getElementById('filterModalOverlay');
    const closeBtn = document.getElementById('filterModalClose');
    const resetBtn = document.getElementById('filterReset');
    const form = document.getElementById('filterForm');
    const matchModeHint = document.getElementById('matchModeHint');
    const modeHints = { and: 'すべての条件に一致', or: 'いずれかの条件に一致' };

    const openModal = () => {
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    };

    const closeModal = () => {
        overlay.classList.remove('active');
        if (!document.getElementById('movieModal')?.classList.contains('active')) {
            document.body.style.overflow = '';
        }
    };

    document.querySelectorAll('.filter-trigger-btn').forEach((button) => {
        button.addEventListener('click', openModal);
    });

    closeBtn?.addEventListener('click', closeModal);
    overlay?.addEventListener('click', (event) => {
        if (event.target === overlay) closeModal();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && overlay?.classList.contains('active')) {
            closeModal();
        }
    });

    document.querySelectorAll('.filter-chip[data-category]').forEach((chip) => {
        chip.addEventListener('click', (event) => {
            if (event.target.tagName === 'INPUT') return;
            document.querySelectorAll('.filter-chip[data-category]').forEach((item) => item.classList.remove('active'));
            chip.classList.add('active');
            const radio = chip.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
        });
    });

    document.querySelectorAll('.filter-chip[data-genre]').forEach((chip) => {
        chip.addEventListener('click', (event) => {
            if (event.target.tagName === 'INPUT') return;
            chip.classList.toggle('active');
            const checkbox = chip.querySelector('input[type="checkbox"]');
            if (checkbox) checkbox.checked = chip.classList.contains('active');
        });
    });

    document.querySelectorAll('.filter-chip[data-year]').forEach((chip) => {
        chip.addEventListener('click', (event) => {
            if (event.target.tagName === 'INPUT') return;
            chip.classList.toggle('active');
            const checkbox = chip.querySelector('input[type="checkbox"]');
            if (checkbox) checkbox.checked = chip.classList.contains('active');
        });
    });

    document.querySelectorAll('.match-mode-option').forEach((option) => {
        option.addEventListener('click', () => {
            document.querySelectorAll('.match-mode-option').forEach((item) => item.classList.remove('active'));
            option.classList.add('active');
            if (matchModeHint) matchModeHint.textContent = modeHints[option.dataset.mode];
        });
    });

    resetBtn?.addEventListener('click', () => {
        document.querySelectorAll('.filter-chip[data-category]').forEach((chip) => chip.classList.remove('active'));
        document.querySelector('.filter-chip[data-category="all"]')?.classList.add('active');
        document.querySelectorAll('.filter-chip[data-genre], .filter-chip[data-year]').forEach((chip) => {
            chip.classList.remove('active');
            const checkbox = chip.querySelector('input[type="checkbox"]');
            if (checkbox) checkbox.checked = false;
        });
        document.querySelectorAll('.match-mode-option').forEach((item) => item.classList.remove('active'));
        document.querySelector('.match-mode-option[data-mode="and"]')?.classList.add('active');
        if (matchModeHint) matchModeHint.textContent = modeHints.and;
    });

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        const category = document.querySelector('.filter-chip[data-category].active')?.dataset.category || 'all';
        const genres = [...document.querySelectorAll('.filter-chip[data-genre].active')].map((chip) => chip.dataset.genre);
        const years = [...document.querySelectorAll('.filter-chip[data-year].active')].map((chip) => chip.dataset.year);
        const matchMode = document.querySelector('.match-mode-option.active')?.dataset.mode || 'and';
        onApply({ ...filters, category, genres, years, matchMode });
        closeModal();
    });
}

export function updateFilterBadges(count) {
    document.querySelectorAll('.filter-trigger-btn').forEach((button) => {
        if (count > 0) {
            button.classList.add('has-filters');
            let badge = button.querySelector('.filter-count-badge');
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'filter-count-badge';
                button.appendChild(badge);
            }
            badge.textContent = count;
        } else {
            button.classList.remove('has-filters');
            button.querySelector('.filter-count-badge')?.remove();
        }
    });
}

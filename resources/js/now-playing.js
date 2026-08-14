import '../css/now-playing.css';

const VISIT_KEY = 'mubiran.now.v1';

export const escapeHtml = (value = '') => String(value).replace(/[&<>'"]/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;',
}[char]));

export function sparklineSvg(points = []) {
    if (points.length < 2) {
        return '<span class="now-sparkline now-sparkline-empty" aria-hidden="true"></span>';
    }
    const values = points.map((point) => Number(point.boxOffice) || 0);
    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = Math.max(1, max - min);
    const width = 84;
    const height = 28;
    const coords = values.map((value, index) => {
        const x = (index / (values.length - 1)) * width;
        const y = height - ((value - min) / span) * (height - 4) - 2;
        return `${x.toFixed(1)},${y.toFixed(1)}`;
    });
    return `<svg class="now-sparkline" viewBox="0 0 ${width} ${height}" width="${width}" height="${height}" aria-hidden="true"><polyline fill="none" stroke="currentColor" stroke-width="2" points="${coords.join(' ')}"/></svg>`;
}

export function readVisit() {
    try {
        return JSON.parse(localStorage.getItem(VISIT_KEY) || 'null');
    } catch {
        return null;
    }
}

export function writeVisit(movies) {
    const totals = {};
    movies.forEach((movie) => {
        totals[movie.key] = movie.boxOffice;
    });
    localStorage.setItem(VISIT_KEY, JSON.stringify({
        visitAt: new Date().toISOString(),
        totals,
    }));
}

export function visitDiffs(movies, previous) {
    if (!previous?.totals) return [];

    return movies
        .map((movie) => {
            const before = previous.totals[movie.key];
            if (typeof before !== 'number' || movie.boxOffice <= before) return null;
            return {
                ...movie,
                visitDelta: movie.boxOffice - before,
                visitDeltaLabel: movie.region === 'global'
                    ? `+${((movie.boxOffice - before) / 100000000).toFixed(2)}億ドル`
                    : `+${((movie.boxOffice - before) / 100000000).toFixed(1)}億円`,
            };
        })
        .filter(Boolean)
        .sort((left, right) => right.visitDelta - left.visitDelta);
}

function formatVisitAt(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    return `${date.getMonth() + 1}/${date.getDate()} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

function sortMovies(movies, sort) {
    const copy = [...movies];
    copy.sort((left, right) => {
        switch (sort) {
        case 'pace':
            return (right.dailyPace || -1) - (left.dailyPace || -1);
        case 'total':
            return right.boxOffice - left.boxOffice;
        case 'rank':
            return (right.rankDelta || -999) - (left.rankDelta || -999);
        case 'days':
            return (left.daysSinceRelease ?? 9999) - (right.daysSinceRelease ?? 9999);
        default:
            return (right.delta || -1) - (left.delta || -1);
        }
    });
    return copy;
}

function movieHref(movie) {
    return `/movies/${encodeURIComponent(movie.slug || movie.key)}/`;
}

function boardCard(movie, unreadKeys) {
    const unread = unreadKeys.has(movie.key) ? '<span class="now-unread">更新</span>' : '';
    const active = movie.isActive ? '<span class="now-pill now-pill-active">公開中</span>' : '';
    const delta = movie.deltaLabel
        ? `<span class="now-delta">${escapeHtml(movie.deltaLabel)}</span>`
        : '<span class="now-delta now-delta-muted">伸び待ち</span>';
    const pace = movie.dailyPaceLabel ? `<span>${escapeHtml(movie.dailyPaceLabel)}</span>` : '';
    const days = movie.daysSincePrev ? `<span>${movie.daysSincePrev}日前の発表比</span>` : '';
    const rank = movie.rank ? `<span>歴代${movie.rank}位</span>` : '';
    const rankDelta = movie.rankDeltaLabel ? `<span>${escapeHtml(movie.rankDeltaLabel)}</span>` : '';
    const released = movie.daysSinceRelease != null ? `<span>公開${movie.daysSinceRelease}日目</span>` : '';
    const passed = movie.passedLabel ? `<p class="now-passed">${escapeHtml(movie.passedLabel)}</p>` : '';
    const poster = movie.posterUrl
        ? `<div class="now-poster" style="background-image:url('${escapeHtml(movie.posterUrl)}')"></div>`
        : '<div class="now-poster now-poster-empty"></div>';

    return `<article class="now-card ${movie.isActive ? 'is-active' : ''}" data-movie-id="${escapeHtml(movie.key)}" role="button" tabindex="0" aria-label="${escapeHtml(movie.title)}の詳細">
        ${poster}
        <div class="now-card-body">
            <div class="now-card-top">
                <h3 class="now-title">${escapeHtml(movie.title)} ${unread}${active}</h3>
                <a class="now-permalink" href="${movieHref(movie)}" onclick="event.stopPropagation()">作品ページ</a>
            </div>
            <div class="now-metrics">
                ${delta}${pace}${days}${rank}${rankDelta}${released}
            </div>
            <div class="now-card-bottom">
                <div>
                    <div class="now-total">${escapeHtml(movie.revenueLabel || movie.revenue || '')}</div>
                    ${passed}
                </div>
                ${sparklineSvg(movie.sparkline)}
            </div>
        </div>
    </article>`;
}

export function renderNowPlaying(data, state, visit) {
    const region = state.nowRegion === 'global' ? 'global' : 'japan';
    const bucket = data[region] || { board: [], today: [], milestones: [] };
    const sorted = sortMovies(bucket.board || [], state.nowSort || 'delta');
    const diffs = visitDiffs(sorted, visit);
    const unreadKeys = new Set(diffs.map((item) => item.key));
    const lastVisitLabel = visit?.visitAt ? formatVisitAt(visit.visitAt) : '';

    const banner = diffs.length
        ? `<section class="now-banner">
            <h2>前回から伸びた作品</h2>
            <p>前回訪問（${escapeHtml(lastVisitLabel)}）からの累計差です。</p>
            <ul>${diffs.slice(0, 6).map((item) => `<li><strong>${escapeHtml(item.title)}</strong> ${escapeHtml(item.visitDeltaLabel)}</li>`).join('')}</ul>
        </section>`
        : (visit
            ? '<section class="now-banner now-banner-quiet"><p>前回訪問から、数字の動いた作品はありません。次の発表を待っています。</p></section>'
            : '<section class="now-banner now-banner-quiet"><p>このブラウザで次回訪れたときに、前回からの変化を表示します。</p></section>');

    const today = (bucket.today || []).length
        ? `<section class="now-today"><h2>直近の発表</h2><ul>${bucket.today.map((item) => `<li><strong>${escapeHtml(item.title)}</strong> ${escapeHtml(item.deltaLabel || '')} ${escapeHtml(item.dailyPaceLabel || '')}</li>`).join('')}</ul></section>`
        : '<section class="now-today"><h2>直近の発表</h2><p>直近72時間で新しい発表はありません。</p></section>';

    const milestones = (bucket.milestones || []).length
        ? `<section class="now-milestones"><h2>最近の到達</h2><ul>${bucket.milestones.map((item) => `<li><strong>${escapeHtml(item.title)}</strong> ${escapeHtml(item.label)}${item.daysToReach != null ? `（公開${item.daysToReach}日目）` : ''} <small>発表ベース</small></li>`).join('')}</ul></section>`
        : '';

    const empty = sorted.length
        ? sorted.map((movie) => boardCard(movie, unreadKeys)).join('')
        : '<p class="now-empty">いま動いている作品はまだありません。公開中フラグ、または直近30日で数字が動いた作品がここに並びます。</p>';

    return `<div class="now-page">
        <p class="now-lead">公開中の作品が、どれくらいの勢いで伸びているかを追う板です。日本の興収は配給発表ベースのため、毎日は動きません。</p>
        ${banner}
        <div class="now-toolbar">
            <div class="now-region" role="group" aria-label="対象">
                <button type="button" class="now-chip ${region === 'japan' ? 'active' : ''}" data-now-region="japan">日本</button>
                <button type="button" class="now-chip ${region === 'global' ? 'active' : ''}" data-now-region="global">世界</button>
            </div>
            <label class="now-sort">並び替え
                <select data-now-sort>
                    <option value="delta" ${state.nowSort === 'delta' ? 'selected' : ''}>伸び額</option>
                    <option value="pace" ${state.nowSort === 'pace' ? 'selected' : ''}>1日ペース</option>
                    <option value="total" ${state.nowSort === 'total' ? 'selected' : ''}>累計興収</option>
                    <option value="rank" ${state.nowSort === 'rank' ? 'selected' : ''}>順位上昇</option>
                    <option value="days" ${state.nowSort === 'days' ? 'selected' : ''}>公開日が新しい</option>
                </select>
            </label>
        </div>
        ${today}
        ${milestones}
        <section class="now-board" aria-label="公開中の勢い">${empty}</section>
        <p class="now-disclaimer">${escapeHtml(data.disclaimer || '')}</p>
    </div>`;
}

export function bindNowPlaying(root, onChange) {
    root.querySelectorAll('[data-now-region]').forEach((button) => {
        button.addEventListener('click', () => onChange({ nowRegion: button.dataset.nowRegion }));
    });
    root.querySelector('[data-now-sort]')?.addEventListener('change', (event) => {
        onChange({ nowSort: event.target.value });
    });
}

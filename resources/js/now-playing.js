import '../css/now-playing.css';

const VISIT_KEY = 'mubiran.now.v1';

export const escapeHtml = (value = '') => String(value).replace(/[&<>'"]/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;',
}[char]));

export function sparklineSvg(points = []) {
    if (points.length < 2) {
        return '';
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

function formatDateTime(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    return `${date.getMonth() + 1}/${date.getDate()} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

function formatDate(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    return `${date.getMonth() + 1}/${date.getDate()}`;
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

function nextMilestoneText(movie) {
    const next = movie.nextMilestone;
    if (!next?.label || !next?.remainingLabel) return '';
    return `${next.label}まで あと${next.remainingLabel}`;
}

function posterDiv(movie, className) {
    return movie.posterUrl
        ? `<div class="${className}" style="background-image:url('${escapeHtml(movie.posterUrl)}')"></div>`
        : `<div class="${className} now-poster-empty"></div>`;
}

function heroSection(movie, unreadKeys) {
    if (!movie) return '';
    const backdrop = movie.posterUrl
        ? `<div class="now-hero-backdrop" style="background-image:url('${escapeHtml(movie.posterUrl)}')"></div>`
        : '';
    const unread = unreadKeys.has(movie.key) ? '<span class="now-unread">更新</span>' : '';
    const milestone = nextMilestoneText(movie);
    const stats = [
        movie.dailyPaceLabel ? `<div class="now-hero-stat"><span>ペース</span><strong>${escapeHtml(movie.dailyPaceLabel)}</strong></div>` : '',
        `<div class="now-hero-stat"><span>累計</span><strong>${escapeHtml(movie.revenueLabel || movie.revenue || '')}</strong></div>`,
        milestone ? `<div class="now-hero-stat now-hero-milestone"><span>次の節目</span><strong>${escapeHtml(milestone)}</strong></div>` : '',
    ].filter(Boolean).join('');
    const context = [
        movie.daysSinceRelease != null ? `公開${movie.daysSinceRelease}日目` : '',
        movie.rank ? `歴代${movie.rank}位` : '',
        movie.rankDeltaLabel ? escapeHtml(movie.rankDeltaLabel) : '',
    ].filter(Boolean).join('・');

    return `<section class="now-hero" data-movie-id="${escapeHtml(movie.key)}" role="button" tabindex="0" aria-label="${escapeHtml(movie.title)}の詳細">
        ${backdrop}
        <div class="now-hero-inner">
            ${posterDiv(movie, 'now-hero-poster')}
            <div class="now-hero-body">
                <p class="now-hero-tag">今いちばん伸びている</p>
                <h2 class="now-hero-title">${escapeHtml(movie.title)} ${unread}</h2>
                <p class="now-hero-delta">${escapeHtml(movie.deltaLabel || '')}<small>${movie.daysSincePrev ? ` ${movie.daysSincePrev}日ぶりの発表` : ' 前回発表から'}</small></p>
                <div class="now-hero-stats">${stats}</div>
                ${movie.passedLabel ? `<p class="now-hero-passed">${escapeHtml(movie.passedLabel)}</p>` : ''}
                ${context ? `<p class="now-hero-context">${context}</p>` : ''}
            </div>
        </div>
    </section>`;
}

function movingCard(movie, unreadKeys) {
    const unread = unreadKeys.has(movie.key) ? '<span class="now-unread">更新</span>' : '';
    const milestone = nextMilestoneText(movie);
    const context = [
        movie.daysSinceRelease != null ? `公開${movie.daysSinceRelease}日目` : '',
        movie.rank ? `歴代${movie.rank}位` : '',
        movie.rankDeltaLabel ? escapeHtml(movie.rankDeltaLabel) : '',
    ].filter(Boolean).join('・');

    return `<article class="now-card" data-movie-id="${escapeHtml(movie.key)}" role="button" tabindex="0" aria-label="${escapeHtml(movie.title)}の詳細">
        ${posterDiv(movie, 'now-poster')}
        <div class="now-card-body">
            <div class="now-card-top">
                <h3 class="now-title">${escapeHtml(movie.title)} ${unread}</h3>
                <a class="now-permalink" href="${movieHref(movie)}" onclick="event.stopPropagation()">作品ページ</a>
            </div>
            <p class="now-card-delta">${escapeHtml(movie.deltaLabel || '')}<small>${movie.daysSincePrev ? ` ${movie.daysSincePrev}日ぶりの発表` : ''}</small></p>
            <div class="now-card-stats">
                ${movie.dailyPaceLabel ? `<span class="now-stat">ペース ${escapeHtml(movie.dailyPaceLabel)}</span>` : ''}
                <span class="now-stat">累計 ${escapeHtml(movie.revenueLabel || movie.revenue || '')}</span>
                ${milestone ? `<span class="now-stat now-stat-milestone">${escapeHtml(milestone)}</span>` : ''}
            </div>
            ${movie.passedLabel ? `<p class="now-passed">${escapeHtml(movie.passedLabel)}</p>` : ''}
            <div class="now-card-foot">
                <span class="now-context">${context}</span>
                ${sparklineSvg(movie.sparkline)}
            </div>
        </div>
    </article>`;
}

function waitingRow(movie) {
    const meta = [
        `累計 ${movie.revenueLabel || movie.revenue || ''}`,
        movie.daysSinceRelease != null ? `公開${movie.daysSinceRelease}日目` : '',
        movie.lastObservedAt ? `${formatDate(movie.lastObservedAt)}記録` : '',
    ].filter(Boolean).join('・');

    return `<li class="now-waiting-row" data-movie-id="${escapeHtml(movie.key)}" role="button" tabindex="0" aria-label="${escapeHtml(movie.title)}の詳細">
        ${posterDiv(movie, 'now-waiting-poster')}
        <div class="now-waiting-body">
            <span class="now-waiting-title">${escapeHtml(movie.title)}</span>
            <span class="now-waiting-meta">${escapeHtml(meta)}</span>
        </div>
        <span class="now-waiting-status">次の発表待ち</span>
    </li>`;
}

function timelineSection(bucket) {
    const items = [];
    (bucket.today || []).forEach((movie) => {
        if (!movie.deltaLabel) return;
        items.push({
            at: movie.lastChangeAt || movie.lastObservedAt || '',
            type: 'delta',
            title: movie.title,
            text: `${movie.deltaLabel}${movie.dailyPaceLabel ? `（${movie.dailyPaceLabel}のペース）` : ''}`,
        });
    });
    (bucket.milestones || []).forEach((milestone) => {
        items.push({
            at: milestone.reachedAt || '',
            type: 'milestone',
            title: milestone.title,
            text: `${milestone.label}${milestone.daysToReach != null ? `（公開${milestone.daysToReach}日目）` : ''}`,
        });
    });
    items.sort((left, right) => (right.at > left.at ? 1 : -1));

    const list = items.slice(0, 10).map((item) => `<li class="now-timeline-item now-timeline-${item.type}">
        <span class="now-timeline-date">${escapeHtml(formatDate(item.at))}</span>
        <span class="now-timeline-text"><strong>${escapeHtml(item.title)}</strong> ${escapeHtml(item.text)}</span>
    </li>`).join('');

    return `<section class="now-timeline" aria-label="最近の動き">
        <h2>最近の動き</h2>
        ${list ? `<ul>${list}</ul>` : '<p class="now-quiet">直近の発表・節目の到達はまだありません。</p>'}
    </section>`;
}

function visitBanner(diffs, visit) {
    if (diffs.length) {
        const when = visit?.visitAt ? formatDateTime(visit.visitAt) : '';
        const chips = diffs.slice(0, 4)
            .map((item) => `<span class="now-visit-chip"><strong>${escapeHtml(item.title)}</strong> ${escapeHtml(item.visitDeltaLabel)}</span>`)
            .join('');
        return `<section class="now-visit"><p class="now-visit-label">前回の訪問${when ? `（${escapeHtml(when)}）` : ''}から伸びた作品</p><div class="now-visit-chips">${chips}</div></section>`;
    }
    if (visit) {
        return '<section class="now-visit now-visit-quiet"><p>前回の訪問から数字の動いた作品はありません。次の発表を待っています。</p></section>';
    }
    return '<section class="now-visit now-visit-quiet"><p>次にこのページを開いたとき、前回からの変化をここに表示します。</p></section>';
}

export function renderNowPlaying(data, state, visit) {
    const region = state.region === 'global' ? 'global' : 'japan';
    const bucket = data[region] || { board: [], today: [], milestones: [] };
    const board = bucket.board || [];
    const moving = sortMovies(board.filter((movie) => movie.delta != null), state.nowSort || 'delta');
    const waiting = board.filter((movie) => movie.delta == null)
        .sort((left, right) => right.boxOffice - left.boxOffice);
    const diffs = visitDiffs(board, visit);
    const unreadKeys = new Set(diffs.map((item) => item.key));

    const hero = [...moving].sort((left, right) => (right.delta || 0) - (left.delta || 0))[0] || null;
    const rest = moving.filter((movie) => movie !== hero);

    const toolbar = rest.length > 1 ? `<div class="now-toolbar">
        <h2 class="now-section-title">伸びている作品</h2>
        <label class="now-sort">並び替え
            <select data-now-sort>
                <option value="delta" ${state.nowSort === 'delta' ? 'selected' : ''}>伸び額</option>
                <option value="pace" ${state.nowSort === 'pace' ? 'selected' : ''}>1日ペース</option>
                <option value="total" ${state.nowSort === 'total' ? 'selected' : ''}>累計興収</option>
                <option value="rank" ${state.nowSort === 'rank' ? 'selected' : ''}>順位上昇</option>
                <option value="days" ${state.nowSort === 'days' ? 'selected' : ''}>公開日が新しい</option>
            </select>
        </label>
    </div>` : (rest.length ? '<h2 class="now-section-title">伸びている作品</h2>' : '');

    const movingSection = rest.length
        ? `<section class="now-board" aria-label="伸びている作品">${rest.map((movie) => movingCard(movie, unreadKeys)).join('')}</section>`
        : (hero ? '' : '<p class="now-quiet now-board-empty">いまは次の発表待ちです。数字が動いた作品がここに並びます。</p>');

    const waitingSection = waiting.length
        ? `<section class="now-waiting" aria-label="次の発表待ち">
            <h2 class="now-section-title">次の発表待ち</h2>
            <ul class="now-waiting-list">${waiting.map(waitingRow).join('')}</ul>
        </section>`
        : '';

    const emptyBoard = board.length
        ? ''
        : '<p class="now-quiet now-board-empty">いま公開中として追跡している作品はありません。データが入り次第ここに並びます。</p>';

    return `<div class="now-page">
        <p class="now-lead">${region === 'japan' ? '日本の興行収入は配給会社の発表ベース。発表のたびに、伸びとペースを記録しています。' : '世界興行収入の動きを記録しています。数字は集計サイトの更新ベースです。'}</p>
        ${visitBanner(diffs, visit)}
        ${heroSection(hero, unreadKeys)}
        ${toolbar}
        ${movingSection}
        ${emptyBoard}
        ${waitingSection}
        ${timelineSection(bucket)}
        <p class="now-disclaimer">${escapeHtml(data.disclaimer || '')}</p>
    </div>`;
}

export function bindNowPlaying(root, onChange) {
    root.querySelector('[data-now-sort]')?.addEventListener('change', (event) => {
        onChange({ nowSort: event.target.value });
    });
}

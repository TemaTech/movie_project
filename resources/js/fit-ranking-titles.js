const MOBILE_RANKING_QUERY = '(max-width: 768px)';
const RANKING_TITLE_MAX_REM = 0.95;
const RANKING_TITLE_MIN_REM = 0.62;

const cssPx = (value) => {
    const parsed = parseFloat(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

function rankingTitleCluster(title) {
    return title.closest('.title-line') || title;
}

function rankingTitleAvailableHeight(title) {
    const row = title.closest('.top-card, .list-item');
    if (!row) return 0;
    const cluster = rankingTitleCluster(title);
    const rowStyle = getComputedStyle(row);
    const clusterStyle = getComputedStyle(cluster);
    return row.clientHeight
        - cssPx(rowStyle.paddingTop)
        - cssPx(rowStyle.paddingBottom)
        - cssPx(clusterStyle.marginTop)
        - cssPx(clusterStyle.marginBottom);
}

function rankingTitleFits(title, available) {
    const cluster = rankingTitleCluster(title);
    return available > 0
        && cluster.scrollHeight <= available + 1
        && cluster.scrollWidth <= cluster.clientWidth + 1;
}

// スマホの順位行は高さが固定。端末幅・金額・Newsボタンでタイトル幅が変わるので、
// 実際の箱に収まらないときだけ文字を縮める。
export function fitRankingTitles(root = document) {
    const titles = root.querySelectorAll('.top-card .movie-title, .list-item .movie-title');
    const mobile = window.matchMedia(MOBILE_RANKING_QUERY).matches;
    const rootPx = cssPx(getComputedStyle(document.documentElement).fontSize) || 16;
    const maxPx = RANKING_TITLE_MAX_REM * rootPx;
    const minPx = RANKING_TITLE_MIN_REM * rootPx;

    titles.forEach((title) => {
        title.style.fontSize = '';
        title.style.lineHeight = '';
        if (!mobile) return;

        const available = rankingTitleAvailableHeight(title);
        if (available <= 0) return;

        title.style.fontSize = `${maxPx}px`;
        if (rankingTitleFits(title, available)) return;

        let low = minPx;
        let high = maxPx;
        let best = minPx;
        for (let step = 0; step < 8; step += 1) {
            const mid = (low + high) / 2;
            title.style.fontSize = `${mid}px`;
            if (rankingTitleFits(title, available)) {
                best = mid;
                low = mid;
            } else {
                high = mid;
            }
        }
        title.style.fontSize = `${best}px`;
        if (!rankingTitleFits(title, available)) {
            title.style.lineHeight = '1.05';
        }
    });
}

let rankingTitleFitFrame = 0;

export function watchRankingTitleFit(shouldFit = () => true) {
    const run = () => {
        if (shouldFit()) fitRankingTitles();
    };
    const schedule = () => {
        cancelAnimationFrame(rankingTitleFitFrame);
        rankingTitleFitFrame = requestAnimationFrame(run);
    };
    window.addEventListener('resize', schedule);
    window.matchMedia(MOBILE_RANKING_QUERY).addEventListener('change', schedule);
    document.fonts?.ready?.then(run);
}

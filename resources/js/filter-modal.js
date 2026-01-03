/**
 * フィルターモーダル - 映画絞り込み機能
 * モーダルの開閉、フィルター選択、フォーム送信を制御
 */

document.addEventListener('DOMContentLoaded', () => {
    // DOM要素の取得
    const overlay = document.getElementById('filterModalOverlay');
    const modal = document.getElementById('filterModal');
    const closeBtn = document.getElementById('filterModalClose');
    const triggerBtn = document.getElementById('filterTriggerBtn');
    const triggerBtnDesktop = document.getElementById('filterTriggerBtnDesktop');
    const resetBtn = document.getElementById('filterReset');
    const form = document.getElementById('filterForm');
    
    // 各フィルター入力
    const genresInput = document.getElementById('genresInput');
    const yearsInput = document.getElementById('yearsInput');
    const matchModeInput = document.getElementById('matchModeInput');
    const matchModeHint = document.getElementById('matchModeHint');
    
    // === モーダル制御 ===
    
    // モーダルを開く
    function openModal() {
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden'; // スクロール防止
    }
    
    // モーダルを閉じる
    function closeModal() {
        overlay.classList.remove('active');
        document.body.style.overflow = ''; // スクロール復帰
    }
    
    // トリガーボタンでモーダルを開く
    if (triggerBtn) {
        triggerBtn.addEventListener('click', openModal);
    }
    
    // デスクトップ用トリガーボタンでもモーダルを開く
    if (triggerBtnDesktop) {
        triggerBtnDesktop.addEventListener('click', openModal);
    }
    
    // 閉じるボタン
    if (closeBtn) {
        closeBtn.addEventListener('click', closeModal);
    }
    
    // オーバーレイクリックで閉じる
    if (overlay) {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                closeModal();
            }
        });
    }
    
    // ESCキーで閉じる
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && overlay && overlay.classList.contains('active')) {
            closeModal();
        }
    });
    
    // === カテゴリ選択（ラジオボタン風） ===
    const categoryChips = document.querySelectorAll('.filter-chip[data-category]');
    categoryChips.forEach(chip => {
        chip.addEventListener('click', (e) => {
            // inputからのバブリングイベントを無視（ダブルクリック防止）
            if (e.target.tagName === 'INPUT') return;
            // すべてのカテゴリチップからactiveを削除
            categoryChips.forEach(c => c.classList.remove('active'));
            // クリックしたチップにactiveを追加
            chip.classList.add('active');
            // ラジオボタンをチェック
            const radio = chip.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
        });
    });
    
    // === ジャンル選択（複数選択） ===
    const genreChips = document.querySelectorAll('.filter-chip[data-genre]');
    genreChips.forEach(chip => {
        chip.addEventListener('click', (e) => {
            // inputからのバブリングイベントを無視（ダブルクリック防止）
            if (e.target.tagName === 'INPUT') return;
            chip.classList.toggle('active');
            const checkbox = chip.querySelector('input[type="checkbox"]');
            if (checkbox) checkbox.checked = chip.classList.contains('active');
            updateGenresInput();
        });
    });
    
    function updateGenresInput() {
        const selectedGenres = [];
        genreChips.forEach(chip => {
            if (chip.classList.contains('active')) {
                selectedGenres.push(chip.dataset.genre);
            }
        });
        if (genresInput) {
            genresInput.value = selectedGenres.join(',');
        }
    }
    
    // === 制作年選択（複数選択） ===
    const yearChips = document.querySelectorAll('.filter-chip[data-year]');
    yearChips.forEach(chip => {
        chip.addEventListener('click', (e) => {
            // inputからのバブリングイベントを無視（ダブルクリック防止）
            if (e.target.tagName === 'INPUT') return;
            chip.classList.toggle('active');
            const checkbox = chip.querySelector('input[type="checkbox"]');
            if (checkbox) checkbox.checked = chip.classList.contains('active');
            updateYearsInput();
        });
    });
    
    function updateYearsInput() {
        const selectedYears = [];
        yearChips.forEach(chip => {
            if (chip.classList.contains('active')) {
                selectedYears.push(chip.dataset.year);
            }
        });
        if (yearsInput) {
            yearsInput.value = selectedYears.join(',');
        }
    }
    
    // === AND/ORスイッチ ===
    const matchModeOptions = document.querySelectorAll('.match-mode-option');
    const modeHints = {
        'and': 'すべての条件に一致',
        'or': 'いずれかの条件に一致'
    };
    
    matchModeOptions.forEach(option => {
        option.addEventListener('click', () => {
            matchModeOptions.forEach(o => o.classList.remove('active'));
            option.classList.add('active');
            const mode = option.dataset.mode;
            if (matchModeInput) matchModeInput.value = mode;
            if (matchModeHint) matchModeHint.textContent = modeHints[mode];
        });
    });
    
    // === リセットボタン ===
    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            // カテゴリをリセット
            categoryChips.forEach(chip => {
                chip.classList.remove('active');
                const radio = chip.querySelector('input[type="radio"]');
                if (radio) radio.checked = false;
            });
            const allChip = document.querySelector('.filter-chip[data-category="all"]');
            if (allChip) {
                allChip.classList.add('active');
                const radio = allChip.querySelector('input[type="radio"]');
                if (radio) radio.checked = true;
            }
            
            // ジャンルをリセット
            genreChips.forEach(chip => {
                chip.classList.remove('active');
                const checkbox = chip.querySelector('input[type="checkbox"]');
                if (checkbox) checkbox.checked = false;
            });
            if (genresInput) genresInput.value = '';
            
            // 年をリセット
            yearChips.forEach(chip => {
                chip.classList.remove('active');
                const checkbox = chip.querySelector('input[type="checkbox"]');
                if (checkbox) checkbox.checked = false;
            });
            if (yearsInput) yearsInput.value = '';
            
            // AND/ORをANDにリセット
            matchModeOptions.forEach(o => o.classList.remove('active'));
            const andOption = document.querySelector('.match-mode-option[data-mode="and"]');
            if (andOption) andOption.classList.add('active');
            if (matchModeInput) matchModeInput.value = 'and';
            if (matchModeHint) matchModeHint.textContent = modeHints['and'];
        });
    }
    
    // === タブ情報の同期 ===
    const filterTabInput = document.getElementById('filterTabInput');
    if (filterTabInput) {
        // タブ切り替え時にフィルターのtab inputも更新
        const originalSwitchTab = window.switchTab;
        if (typeof originalSwitchTab === 'function') {
            window.switchTab = function(tab) {
                originalSwitchTab(tab);
                filterTabInput.value = tab;
            };
        }
    }
    
    // === 絞り込みボタンの状態更新 ===
    function updateTriggerButtonState() {
        if (!triggerBtn) return;
        
        const hasGenres = genresInput && genresInput.value.length > 0;
        const hasYears = yearsInput && yearsInput.value.length > 0;
        const hasCategory = document.querySelector('.filter-chip[data-category].active:not([data-category="all"])');
        
        const filterCount = (hasGenres ? genresInput.value.split(',').length : 0) +
                           (hasYears ? yearsInput.value.split(',').length : 0) +
                           (hasCategory ? 1 : 0);
        
        if (filterCount > 0) {
            triggerBtn.classList.add('has-filters');
            let badge = triggerBtn.querySelector('.filter-count-badge');
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'filter-count-badge';
                triggerBtn.appendChild(badge);
            }
            badge.textContent = filterCount;
        } else {
            triggerBtn.classList.remove('has-filters');
            const badge = triggerBtn.querySelector('.filter-count-badge');
            if (badge) badge.remove();
        }
    }
    
    // 初期状態の更新
    updateTriggerButtonState();
});

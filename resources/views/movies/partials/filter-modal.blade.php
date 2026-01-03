{{-- 絞り込みモーダル --}}
<div class="filter-modal-overlay" id="filterModalOverlay">
    <div class="filter-modal" id="filterModal">
        {{-- ヘッダー --}}
        <div class="filter-modal-header">
            <h2 class="filter-modal-title">絞り込み検索</h2>
            <button class="filter-modal-close" id="filterModalClose" aria-label="閉じる">×</button>
        </div>

        <form id="filterForm" action="" method="GET">
            {{-- タブ保持用hidden --}}
            <input type="hidden" name="tab" id="filterTabInput" value="{{ request('tab', 'global') }}">
            
            {{-- カテゴリ区分（実写/アニメ/すべて） --}}
            <div class="filter-section">
                <h3 class="filter-section-title">カテゴリ</h3>
                <div class="filter-chips">
                    <label class="filter-chip category-all {{ !request('category') || request('category') === 'all' ? 'active' : '' }}" data-category="all">
                        <input type="radio" name="category" value="all" {{ !request('category') || request('category') === 'all' ? 'checked' : '' }} hidden>
                        すべて
                    </label>
                    <label class="filter-chip category-live {{ request('category') === 'live' ? 'active' : '' }}" data-category="live">
                        <input type="radio" name="category" value="live" {{ request('category') === 'live' ? 'checked' : '' }} hidden>
                        実写映画
                    </label>
                    <label class="filter-chip category-anime {{ request('category') === 'anime' ? 'active' : '' }}" data-category="anime">
                        <input type="radio" name="category" value="anime" {{ request('category') === 'anime' ? 'checked' : '' }} hidden>
                        アニメ映画
                    </label>
                </div>
            </div>

            {{-- ジャンル --}}
            <div class="filter-section">
                <h3 class="filter-section-title">ジャンル</h3>
                <div class="filter-chips" id="genreChips">
                    @php
                        $selectedGenres = request('genres') ? explode(',', request('genres')) : [];
                    @endphp
                    @foreach($availableGenres as $genre)
                        @if($genre !== 'アニメ' && $genre !== 'アニメーション')
                            <label class="filter-chip {{ in_array($genre, $selectedGenres) ? 'active' : '' }}" data-genre="{{ $genre }}">
                                <input type="checkbox" name="genre_item[]" value="{{ $genre }}" {{ in_array($genre, $selectedGenres) ? 'checked' : '' }} hidden>
                                {{ $genre }}
                            </label>
                        @endif
                    @endforeach
                </div>
                <input type="hidden" name="genres" id="genresInput" value="{{ request('genres', '') }}">
            </div>

            {{-- 制作年 --}}
            <div class="filter-section">
                <h3 class="filter-section-title">制作年</h3>
                <div class="filter-chips" id="yearChips">
                    @php
                        $currentYear = (int)date('Y');
                        $selectedYears = request('years') ? explode(',', request('years')) : [];
                        
                        // 直近3年（1年単位）
                        $recentYears = [$currentYear, $currentYear - 1, $currentYear - 2];
                        
                        // 年代（10年単位）
                        $decades = ['2020s', '2010s', '2000s', 'older'];
                        $decadeLabels = [
                            '2020s' => '2020年代',
                            '2010s' => '2010年代',
                            '2000s' => '2000年代',
                            'older' => 'それ以前'
                        ];
                    @endphp
                    
                    {{-- 直近3年 --}}
                    @foreach($recentYears as $year)
                        <label class="filter-chip {{ in_array((string)$year, $selectedYears) ? 'active' : '' }}" data-year="{{ $year }}">
                            <input type="checkbox" name="year_item[]" value="{{ $year }}" {{ in_array((string)$year, $selectedYears) ? 'checked' : '' }} hidden>
                            {{ $year }}年
                        </label>
                    @endforeach
                    
                    {{-- 年代 --}}
                    @foreach($decades as $decade)
                        <label class="filter-chip {{ in_array($decade, $selectedYears) ? 'active' : '' }}" data-year="{{ $decade }}">
                            <input type="checkbox" name="year_item[]" value="{{ $decade }}" {{ in_array($decade, $selectedYears) ? 'checked' : '' }} hidden>
                            {{ $decadeLabels[$decade] }}
                        </label>
                    @endforeach
                </div>
                <input type="hidden" name="years" id="yearsInput" value="{{ request('years', '') }}">
            </div>

            {{-- 検索モード（AND/OR） --}}
            <div class="filter-section">
                <div class="match-mode-container">
                    <span class="match-mode-label">検索モード:</span>
                    <div class="match-mode-switch">
                        <button type="button" class="match-mode-option {{ !request('match_mode') || request('match_mode') === 'and' ? 'active' : '' }}" data-mode="and">
                            AND
                        </button>
                        <button type="button" class="match-mode-option {{ request('match_mode') === 'or' ? 'active' : '' }}" data-mode="or">
                            OR
                        </button>
                    </div>
                    <span class="match-mode-hint" id="matchModeHint">すべての条件に一致</span>
                    <input type="hidden" name="match_mode" id="matchModeInput" value="{{ request('match_mode', 'and') }}">
                </div>
            </div>

            {{-- アクションボタン --}}
            <div class="filter-actions">
                <button type="button" class="filter-btn filter-btn-reset" id="filterReset">リセット</button>
                <button type="submit" class="filter-btn filter-btn-apply">絞り込む</button>
            </div>
        </form>
    </div>
</div>

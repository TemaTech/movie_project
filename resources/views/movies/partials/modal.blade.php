<div id="movieModal" class="modal-overlay" onclick="closeModal(event)">
    <div class="modal-content" onclick="event.stopPropagation()">
        <button class="modal-close" onclick="closeModal(event)">&times;</button>
        
        <div id="modalLoading" class="modal-loading">
            <div class="spinner"></div>
            <p>情報を取得中...</p>
        </div>

        <div id="modalBody" class="modal-body" style="display: none;">
            <div class="modal-header">
                <div class="modal-poster-wrapper">
                    <img id="modalPoster" src="" alt="Poster" class="modal-poster">
                </div>
                <div class="modal-title-section">
                    <h2 id="modalTitle" class="modal-title">Movie Title</h2>
                    <div id="modalTitleEn" class="modal-title-en" style="display: none;"></div>
                    <div class="modal-meta">
                        <span id="modalYear" class="meta-tag year">202X</span>
                        <span id="modalRuntime" class="meta-tag runtime">120分</span>
                        <span id="modalRating" class="meta-tag rating">★ 8.5</span>
                    </div>
                    <p id="modalTagline" class="modal-tagline">"The tagline goes here..."</p>
                    <div class="modal-buttons">
                        <!-- Future: Trailer button, etc -->
                    </div>
                </div>
            </div>

            <div class="modal-grid">
                <div class="modal-section main-info">
                    <h3>あらすじ</h3>
                    <p id="modalOverview" class="modal-text">Overview text...</p>
                    
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
                <div id="modalCast" class="cast-grid">
                    <!-- Cast items injected here -->
                </div>
            </div>
        </div>
    </div>
</div>

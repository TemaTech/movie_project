@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination Navigation" class="cinematic-pagination">
        {{-- Previous Page Link --}}
        @if ($paginator->onFirstPage())
            <span class="pagination-btn disabled" aria-disabled="true">
                &lsaquo; 前へ
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="pagination-btn">
                &lsaquo; 前へ
            </a>
        @endif

        {{-- Next Page Link --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="pagination-btn">
                次へ &rsaquo;
            </a>
        @else
            <span class="pagination-btn disabled" aria-disabled="true">
                次へ &rsaquo;
            </span>
        @endif
    </nav>
@endif

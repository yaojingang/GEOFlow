<footer class="ent-footer">
    <div class="ent-shell">
        <div class="ent-footer__grid">
            <div>
                <span class="ent-footer__label">Platform</span>
                <a href="{{ route('site.home').'#platform' }}">平台能力</a>
                <a href="{{ route('site.home').'#ecosystem' }}">开源架构</a>
                <a href="{{ route('site.home').'#solutions' }}">行业方案</a>
            </div>
            <div>
                <span class="ent-footer__label">Resources</span>
                <a href="{{ route('site.archive') }}">洞察归档</a>
                <a href="{{ route('site.about') }}">关于 GEOFlow</a>
                @foreach($navCategories->take(3) as $categoryItem)
                    <a href="{{ route('site.category', $categoryItem->slug) }}">{{ $categoryItem->name }}</a>
                @endforeach
            </div>
            <div>
                <span class="ent-footer__label">Open Source</span>
                <a href="https://github.com/yaojingang/GEOFlow" target="_blank" rel="noopener noreferrer">GitHub</a>
                <a href="https://github.com/yaojingang/GEOFlow/issues" target="_blank" rel="noopener noreferrer">Issues</a>
                <a href="https://github.com/yaojingang/GEOFlow/releases" target="_blank" rel="noopener noreferrer">Releases</a>
            </div>
            <div>
                <span class="ent-footer__label">Enterprise</span>
                <a href="{{ route('site.home').'#contact' }}">联系团队</a>
                <a href="{{ route('site.home').'#case-study' }}">客户案例</a>
                <span class="ent-footer__demo">以上企业信息用于模板演示</span>
            </div>
        </div>

        <div class="ent-footer__bottom">
            <div>
                {{ $footerCopyright !== '' ? $footerCopyright : '© '.date('Y').' '.$siteName.'. All rights reserved.' }}
                @include('site.partials.footer-filing')
            </div>
            <span>Built with GEOFlow · Enterprise Signature 21</span>
        </div>
    </div>
</footer>

<main class="qt-page">
    <div class="qt-container">
        <nav class="qt-breadcrumb">
            <a href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'home')); ?>"><?php echo __('front.nav.home'); ?></a>
            <span>/</span>
            <?php if (!empty($year) && !empty($month)): ?>
                <a href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'archive')); ?>"><?php echo __('front.archive.title'); ?></a>
                <span>/</span>
                <span><?php echo htmlspecialchars($archive_title); ?></span>
            <?php else: ?>
                <span><?php echo __('front.archive.title'); ?></span>
            <?php endif; ?>
        </nav>

        <section class="qt-hero">
            <div class="qt-hero__eyebrow"><?php echo __('front.archive.title'); ?></div>
            <h1 class="qt-hero__title"><?php echo htmlspecialchars($archive_title); ?></h1>
            <p class="qt-hero__copy">
                <?php if (!empty($year) && !empty($month)): ?>
                    <?php echo __('front.archive.month_description', ['count' => $total_count]); ?>
                <?php else: ?>
                    <?php echo __('front.archive.overview_description'); ?>
                <?php endif; ?>
            </p>
        </section>

        <?php if (!empty($year) && !empty($month)): ?>
            <?php if (empty($articles)): ?>
                <section class="qt-empty">
                    <h3 class="text-xl font-semibold text-gray-900 mb-2"><?php echo __('front.articles.empty_title'); ?></h3>
                    <p class="text-gray-600"><?php echo __('front.archive.month_empty_description'); ?></p>
                </section>
            <?php else: ?>
                <section class="qt-list">
                    <?php foreach ($articles as $article): ?>
                        <article class="qt-card">
                            <div class="qt-meta">
                                <?php if (!empty($article['category_name'])): ?>
                                    <span class="qt-chip"><?php echo htmlspecialchars($article['category_name']); ?></span>
                                <?php endif; ?>
                                <time datetime="<?php echo htmlspecialchars($article['published_at'] ?: $article['created_at']); ?>"><?php echo htmlspecialchars(geoflow_preview_public_date($article['published_at'] ?: $article['created_at'], 'short')); ?></time>
                            </div>
                            <h2 class="qt-card__title"><a href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'article', ['slug' => $article['slug']])); ?>"><?php echo htmlspecialchars($article['title']); ?></a></h2>
                            <p class="qt-card__excerpt"><?php echo htmlspecialchars(geoflow_theme_summary_text($article, 160)); ?></p>
                            <div class="qt-card__footer">
                                <div></div>
                                <a class="qt-card__link" href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'article', ['slug' => $article['slug']])); ?>"><?php echo __('front.home.read_more'); ?> →</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
                <?php if ($total_pages > 1): ?>
                    <div class="mt-10">
                        <?php echo generate_pagination($page, $total_pages, geoflow_theme_preview_url($theme_id, 'archive_month', ['year' => $year, 'month' => $month]) . '?page='); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php else: ?>
            <?php if (empty($archives)): ?>
                <section class="qt-empty">
                    <h3 class="text-xl font-semibold text-gray-900 mb-2"><?php echo __('front.archive.empty_title'); ?></h3>
                    <p class="text-gray-600"><?php echo __('front.archive.empty_description'); ?></p>
                </section>
            <?php else: ?>
                <section class="qt-list">
                    <?php foreach ($archives as $archive): ?>
                        <a class="qt-archive-panel" href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'archive_month', ['year' => $archive['year'], 'month' => $archive['month']])); ?>">
                            <div class="qt-meta" style="margin-bottom:8px;">
                                <span><?php echo htmlspecialchars($archive['year']); ?></span>
                                <span><?php echo app_locale() === 'en' ? date('F', strtotime('2000-' . $archive['month'] . '-01')) : intval($archive['month']) . '月'; ?></span>
                            </div>
                            <div class="text-lg font-semibold text-gray-900"><?php echo __('front.archive.article_count', ['count' => intval($archive['count'])]); ?></div>
                        </a>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

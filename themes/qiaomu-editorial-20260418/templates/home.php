<main class="qt-page">
    <div class="qt-container">
        <?php if ($search === '' && empty($category) && (int) $page === 1): ?>
            <section class="qt-hero">
                <div class="qt-hero__eyebrow"><?php echo htmlspecialchars($site_title); ?></div>
                <h1 class="qt-hero__title"><?php echo htmlspecialchars($site_title); ?></h1>
                <p class="qt-hero__copy"><?php echo htmlspecialchars(!empty($site_subtitle) ? $site_subtitle : $site_description); ?></p>
            </section>
        <?php endif; ?>

        <?php if ($search !== ''): ?>
            <h2 class="qt-section-title"><?php echo __('front.home.search_results'); ?> · <?php echo htmlspecialchars($search); ?></h2>
        <?php elseif (!empty($category)): ?>
            <section class="qt-hero">
                <div class="qt-hero__eyebrow"><?php echo __('front.nav.categories'); ?></div>
                <h1 class="qt-hero__title"><?php echo htmlspecialchars($category['name']); ?></h1>
                <p class="qt-hero__copy"><?php echo htmlspecialchars(!empty($category['description']) ? $category['description'] : __('front.category.meta_fallback', ['name' => $category['name']])); ?></p>
            </section>
        <?php endif; ?>

        <?php if ($search === '' && empty($category) && (int) $page === 1 && !empty($featured_articles)): ?>
            <h2 class="qt-section-title"><?php echo __('front.home.featured_articles'); ?></h2>
            <section class="qt-list" style="margin-bottom:32px;">
                <?php foreach ($featured_articles as $article): ?>
                    <article class="qt-card">
                        <div class="qt-meta">
                            <span class="qt-chip qt-chip--accent"><?php echo __('front.home.featured_badge'); ?></span>
                            <?php if (!empty($article['category_name'])): ?>
                                <span class="qt-chip"><?php echo htmlspecialchars($article['category_name']); ?></span>
                            <?php endif; ?>
                            <time datetime="<?php echo htmlspecialchars($article['published_at'] ?: $article['created_at']); ?>"><?php echo htmlspecialchars(geoflow_preview_public_date($article['published_at'] ?: $article['created_at'])); ?></time>
                        </div>
                        <h3 class="qt-card__title"><a href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'article', ['slug' => $article['slug']])); ?>"><?php echo htmlspecialchars($article['title']); ?></a></h3>
                        <p class="qt-card__excerpt"><?php echo htmlspecialchars(geoflow_theme_summary_text($article, 160)); ?></p>
                        <div class="qt-card__footer">
                            <div class="qt-meta" style="margin:0;">
                                <?php foreach (array_slice(get_article_tags($article['id']), 0, 3) as $tag): ?>
                                    <span class="qt-chip"><?php echo htmlspecialchars($tag['name']); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <a class="qt-card__link" href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'article', ['slug' => $article['slug']])); ?>"><?php echo __('front.home.read_more'); ?> →</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <h2 class="qt-section-title"><?php echo htmlspecialchars($view_title); ?></h2>

        <?php if (empty($articles)): ?>
            <section class="qt-empty">
                <h3 class="text-xl font-semibold text-gray-900 mb-2"><?php echo __('front.articles.empty_title'); ?></h3>
                <p class="text-gray-600"><?php echo __('front.home.empty_description'); ?></p>
            </section>
        <?php else: ?>
            <section class="qt-list">
                <?php foreach ($articles as $article): ?>
                    <article class="qt-card">
                        <div class="qt-meta">
                            <?php if (!empty($article['category_name'])): ?>
                                <span class="qt-chip"><?php echo htmlspecialchars($article['category_name']); ?></span>
                            <?php endif; ?>
                            <time datetime="<?php echo htmlspecialchars($article['published_at'] ?: $article['created_at']); ?>"><?php echo htmlspecialchars(geoflow_preview_public_date($article['published_at'] ?: $article['created_at'])); ?></time>
                        </div>
                        <h3 class="qt-card__title"><a href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'article', ['slug' => $article['slug']])); ?>"><?php echo htmlspecialchars($article['title']); ?></a></h3>
                        <p class="qt-card__excerpt"><?php echo htmlspecialchars(geoflow_theme_summary_text($article, 160)); ?></p>
                        <div class="qt-card__footer">
                            <div class="qt-meta" style="margin:0;">
                                <?php foreach (array_slice(get_article_tags($article['id']), 0, 3) as $tag): ?>
                                    <span class="qt-chip"><?php echo htmlspecialchars($tag['name']); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <a class="qt-card__link" href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'article', ['slug' => $article['slug']])); ?>"><?php echo __('front.home.read_more'); ?> →</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <?php if ($total_pages > 1): ?>
                <div class="mt-10">
                    <?php echo generate_pagination($page, $total_pages, geoflow_theme_preview_url($theme_id, 'home') . '?page='); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

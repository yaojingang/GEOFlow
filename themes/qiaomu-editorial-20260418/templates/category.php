<main class="qt-page">
    <div class="qt-container">
        <nav class="qt-breadcrumb">
            <a href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'home')); ?>"><?php echo __('front.nav.home'); ?></a>
            <span>/</span>
            <span><?php echo htmlspecialchars($category['name']); ?></span>
        </nav>

        <section class="qt-hero">
            <div class="qt-hero__eyebrow"><?php echo __('front.nav.categories'); ?></div>
            <h1 class="qt-hero__title"><?php echo htmlspecialchars($category['name']); ?></h1>
            <p class="qt-hero__copy"><?php echo htmlspecialchars(!empty($category['description']) ? $category['description'] : __('front.category.meta_fallback', ['name' => $category['name']])); ?></p>
        </section>

        <?php if (empty($articles)): ?>
            <section class="qt-empty">
                <h3 class="text-xl font-semibold text-gray-900 mb-2"><?php echo __('front.articles.empty_title'); ?></h3>
                <p class="text-gray-600"><?php echo __('front.category.empty_description'); ?></p>
            </section>
        <?php else: ?>
            <section class="qt-list">
                <?php foreach ($articles as $article): ?>
                    <article class="qt-card">
                        <div class="qt-meta">
                            <?php if (!empty($article['is_featured'])): ?>
                                <span class="qt-chip qt-chip--accent"><?php echo __('front.home.featured_badge'); ?></span>
                            <?php endif; ?>
                            <span class="qt-chip"><?php echo htmlspecialchars($category['name']); ?></span>
                            <time datetime="<?php echo htmlspecialchars($article['published_at'] ?: $article['created_at']); ?>"><?php echo htmlspecialchars(geoflow_preview_public_date($article['published_at'] ?: $article['created_at'])); ?></time>
                        </div>
                        <h2 class="qt-card__title"><a href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'article', ['slug' => $article['slug']])); ?>"><?php echo htmlspecialchars($article['title']); ?></a></h2>
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
                    <?php echo generate_pagination($page, $total_pages, geoflow_theme_preview_url($theme_id, 'category', ['slug' => $category['slug'] ?: $category['id']]) . '?page='); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

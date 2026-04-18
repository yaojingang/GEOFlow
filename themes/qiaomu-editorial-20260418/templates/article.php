<main class="qt-page">
    <div class="qt-container">
        <article class="qt-article">
            <nav class="qt-breadcrumb" aria-label="<?php echo htmlspecialchars(__('front.article.breadcrumb'), ENT_QUOTES); ?>">
                <a href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'home')); ?>"><?php echo __('front.nav.home'); ?></a>
                <span>/</span>
                <?php if (!empty($article['category_name'])): ?>
                    <a href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'category', ['slug' => $article['category_slug'] ?: $article['category_id']])); ?>"><?php echo htmlspecialchars($article['category_name']); ?></a>
                    <span>/</span>
                <?php endif; ?>
                <span><?php echo htmlspecialchars($article['title']); ?></span>
            </nav>

            <header class="mb-8">
                <?php if (!empty($article['category_name'])): ?>
                    <div class="qt-chip qt-chip--accent"><?php echo htmlspecialchars($article['category_name']); ?></div>
                <?php endif; ?>
                <h1 class="qt-article__title"><?php echo htmlspecialchars($article['title']); ?></h1>
                <div class="qt-meta">
                    <span><?php echo __('front.article.published_on', ['date' => geoflow_preview_public_date($article['published_at'] ?: $article['created_at'])]); ?></span>
                </div>
                <?php if (!empty($article_excerpt)): ?>
                    <div class="qt-summary">
                        <p class="qt-article__dek"><?php echo htmlspecialchars($article_excerpt); ?></p>
                    </div>
                <?php endif; ?>
            </header>

            <div class="qt-prose">
                <?php echo markdown_to_html($article_content); ?>
            </div>

            <?php if (!empty($article_tags)): ?>
                <div class="qt-meta mt-6">
                    <?php foreach ($article_tags as $tag): ?>
                        <span class="qt-chip"><?php echo htmlspecialchars($tag['name']); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($article_detail_ad)): ?>
                <aside class="qt-callout">
                    <?php if (!empty($article_detail_ad['badge'])): ?>
                        <div class="qt-callout__label"><?php echo htmlspecialchars($article_detail_ad['badge']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($article_detail_ad['title'])): ?>
                        <h3 class="qt-callout__title"><?php echo htmlspecialchars($article_detail_ad['title']); ?></h3>
                    <?php endif; ?>
                    <p class="qt-callout__copy"><?php echo htmlspecialchars($article_detail_ad['copy']); ?></p>
                    <a href="<?php echo htmlspecialchars($article_detail_ad['button_url']); ?>" class="qt-button"><?php echo htmlspecialchars($article_detail_ad['button_text']); ?></a>
                </aside>
            <?php endif; ?>

            <?php if (!empty($related_articles)): ?>
                <section class="qt-related">
                    <h2 class="qt-section-title"><?php echo __('front.article.related_articles'); ?></h2>
                    <ul>
                        <?php foreach ($related_articles as $related): ?>
                            <li><a href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'article', ['slug' => $related['slug']])); ?>"><?php echo htmlspecialchars($related['title']); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        </article>
    </div>
</main>

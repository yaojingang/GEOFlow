<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageLabel }}</title>
    <style>
        :root { color-scheme: light; font-family: ui-sans-serif, system-ui, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f8fafc; color: #0f172a; }
        .shell { width: min(70rem, 100%); margin: 0 auto; padding: 2rem 1.25rem 4rem; }
        .notice { margin-bottom: 1.5rem; border: 1px solid #bfdbfe; border-radius: 0.75rem; background: #eff6ff; padding: 0.75rem 1rem; color: #1e40af; font-size: 0.875rem; }
        .hero { border-radius: 1.25rem; background: #0f172a; padding: clamp(2rem, 6vw, 5rem); color: #f8fafc; }
        .eyebrow { margin: 0 0 1rem; color: #93c5fd; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase; }
        h1 { max-width: 48rem; margin: 0; font-size: clamp(2rem, 6vw, 4.5rem); line-height: 1.05; }
        .intro { max-width: 42rem; margin: 1.25rem 0 0; color: #cbd5e1; font-size: 1.05rem; line-height: 1.75; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 1rem; margin-top: 1.5rem; }
        .card { min-height: 11rem; border: 1px solid #e2e8f0; border-radius: 1rem; background: #fff; padding: 1.25rem; }
        .card h2 { margin: 0; font-size: 1.1rem; }
        .card p { margin: 0.75rem 0 0; color: #475569; line-height: 1.65; }
    </style>
</head>
<body data-safe-theme-preview data-preview-page="{{ $page }}">
    <main class="shell">
        <div class="notice">{{ __('admin.theme_replication.safe_preview.notice') }}</div>
        <section class="hero">
            <p class="eyebrow">{{ $pageLabel }}</p>
            <h1>{{ $title }}</h1>
            <p class="intro">{{ $description }}</p>
        </section>
        <section class="grid">
            @foreach ($cards as $card)
                <article class="card">
                    <h2>{{ $card['title'] }}</h2>
                    <p>{{ $card['description'] }}</p>
                </article>
            @endforeach
        </section>
    </main>
</body>
</html>

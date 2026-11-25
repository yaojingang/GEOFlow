<?php
require "/opt/GEOFlow/vendor/autoload.php";
$app = require "/opt/GEOFlow/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$out = "/opt/site/geo/public/blog";
if (!is_dir($out)) { mkdir($out, 0755, true); }

$arts = App\Models\Article::query()->where("status","published")->orderByDesc("published_at")->get();
$n = 0; $items = [];
foreach ($arts as $a) {
    $bodyHtml = Illuminate\Support\Str::markdown((string)$a->content);
    $title = htmlspecialchars((string)$a->title, ENT_QUOTES, "UTF-8");
    $raw = strip_tags($bodyHtml);
    $desc = htmlspecialchars(mb_substr($a->meta_description ?: ($a->excerpt ?: $raw), 0, 140), ENT_QUOTES, "UTF-8");
    $date = $a->published_at ?: $a->updated_at;
    $dateStr = $date ? $date->format("Y-m-d") : date("Y-m-d");
    $url = "https://geo.aixgn.cn/blog/{$a->slug}.html";
    $ld = json_encode(["@context"=>"https://schema.org","@type"=>"Article","headline"=>(string)$a->title,"datePublished"=>$dateStr,"dateModified"=>$dateStr,"author"=>["@type"=>"Organization","name"=>"新概念AI"],"publisher"=>["@type"=>"Organization","name"=>"新概念AI"],"mainEntityOfPage"=>$url,"description"=>htmlspecialchars_decode($desc)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.' | 新概念AI</title><meta name="description" content="'.$desc.'"><link rel="canonical" href="'.$url.'"><script type="application/ld+json">'.$ld.'</script><style>body{margin:0;background:#0b1220;color:#e6edf7;font:16px/1.9 -apple-system,Segoe UI,Microsoft YaHei,sans-serif}.wrap{max-width:820px;margin:0 auto;padding:32px 20px 80px}h1{font-size:28px;line-height:1.5;margin:8px 0 12px}.meta{color:#7f8ea3;font-size:13px;margin-bottom:24px;border-bottom:1px solid #1e2c42;padding-bottom:16px}.body h2{font-size:21px;margin:32px 0 12px;color:#4dd0ff}.body h3{font-size:17px;margin:22px 0 8px}.body p{margin:12px 0}.body li{margin:6px 0}.body a{color:#4dd0ff}.back{display:inline-block;margin-bottom:20px;color:#4dd0ff;text-decoration:none;font-size:14px}.cta{margin-top:40px;padding:20px;border:1px solid #1e3f5c;border-radius:10px;background:#122033}</style></head><body><div class="wrap"><a class="back" href="/blog/">← 全部文章</a><h1>'.$title.'</h1><div class="meta">'.$dateStr.' · 新概念AI · GEO 内容中心</div><div class="body">'.$bodyHtml.'</div><div class="cta">需要为你的学校/机构落地 AI 课程与智慧停车方案？<a href="/contact.html">联系我们</a></div></div></body></html>';
    file_put_contents($out."/".$a->slug.".html", $html);
    $items[] = ["slug"=>$a->slug,"title"=>$a->title,"date"=>$dateStr,"desc"=>strip_tags($desc)];
    $n++;
}

$list = "";
foreach ($items as $it) {
    $t = htmlspecialchars((string)$it["title"], ENT_QUOTES, "UTF-8");
    $list .= '<li><a href="/blog/'.$it["slug"].'.html">'.$t.'</a><span class="d">'.$it["date"].'</span></li>';
}
$idx = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GEO 内容中心 | 新概念AI</title><meta name="description" content="新概念AI 内容中心：AI 教育、智慧停车、无人机赛事与物业数字化的落地方法与案例。"><style>body{margin:0;background:#0b1220;color:#e6edf7;font:16px/1.8 -apple-system,Segoe UI,Microsoft YaHei,sans-serif}.wrap{max-width:820px;margin:0 auto;padding:40px 20px 80px}h1{font-size:26px}.sub{color:#7f8ea3;font-size:14px;margin-bottom:28px}ul{list-style:none;padding:0}li{padding:14px 0;border-bottom:1px solid #16233a}li a{color:#e6edf7;text-decoration:none;font-size:17px}li a:hover{color:#4dd0ff}.d{float:right;color:#7f8ea3;font-size:13px}</style></head><body><div class="wrap"><h1>GEO 内容中心</h1><div class="sub">共 '.count($items).' 篇 · 新概念AI</div><ul>'.$list.'</ul></div></body></html>';
file_put_contents($out."/index.html", $idx);

// sitemap
$sm = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
$sm .= '<url><loc>https://geo.aixgn.cn/blog/</loc></url>';
foreach ($items as $it) { $sm .= '<url><loc>https://geo.aixgn.cn/blog/'.$it["slug"].'.html</loc><lastmod>'.$it["date"].'</lastmod></url>'; }
$sm .= '</urlset>';
file_put_contents($out."/sitemap.xml", $sm);

echo "written=$n articles, list=".count($items)."\n";

"""Build the offline, self-contained GEOFlow topic prototype."""
from pathlib import Path
folder=Path(__file__).resolve().parent
css=(folder/'styles.css').read_text()
js=(folder/'app.js').read_text()
html='''<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 40 40%27%3E%3Crect width=%2740%27 height=%2740%27 rx=%276%27 fill=%27%231d426f%27/%3E%3Ctext x=%2720%27 y=%2729%27 text-anchor=%27middle%27 font-family=%27Arial%27 font-size=%2727%27 fill=%27white%27%3EG%3C/text%3E%3C/svg%3E"><meta name="robots" content="noindex,nofollow"><title>GEOFlow 专题交互原型</title><style>'''+css+'''</style></head>
<body><a class="skip" href="#main-content" data-action="skip-main">跳到主要内容</a><div id="app"></div><div id="toast" class="toast" role="status" aria-live="polite" hidden></div><script>'''+js+'''</script></body></html>'''
(folder/'index.html').write_text(html)
print('Built index.html')

<?php
/**
 * GEO+AI内容生成系统 - PHP内置服务器路由器
 *
 * @author 姚金刚
 * @version 2.0
 * @date 2025-10-04
 */

// 获取请求的URI
$requestUri = $_SERVER['REQUEST_URI'];
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$queryString = parse_url($requestUri, PHP_URL_QUERY);
$adminBasePath = '/' . trim(getenv('ADMIN_BASE_PATH') ?: 'geo_admin', '/');
$legacyAdminPath = '/admin';

// 移除查询参数
$path = rtrim($requestPath, '/');

// 静态文件直接返回
if (preg_match('/\.(css|js|txt|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$/', $path)) {
    return false; // 让PHP内置服务器处理静态文件
}

// 上传目录中的脚本文件一律禁止执行
if (preg_match('#^/uploads/.*\.(php|phtml|phar)$#i', $path)) {
    header('HTTP/1.0 404 Not Found');
    echo 'Not Found';
    return true;
}

// 主题预览：/preview/theme-id/article/slug
if (preg_match('/^\/preview\/([a-zA-Z0-9\-_]+)\/article\/([a-zA-Z0-9\-_]+)\/?$/', $path, $matches)) {
    $_GET['theme'] = $matches[1];
    $_GET['preview_page'] = 'article';
    $_GET['slug'] = $matches[2];
    require_once __DIR__ . '/theme-preview.php';
    return true;
}

// 主题预览：/preview/theme-id/category/slug
if (preg_match('/^\/preview\/([a-zA-Z0-9\-_]+)\/category\/([a-zA-Z0-9\-_]+)\/?$/', $path, $matches)) {
    $_GET['theme'] = $matches[1];
    $_GET['preview_page'] = 'category';
    $_GET['slug'] = $matches[2];
    require_once __DIR__ . '/theme-preview.php';
    return true;
}

// 主题预览：/preview/theme-id/archive/YYYY/MM
if (preg_match('/^\/preview\/([a-zA-Z0-9\-_]+)\/archive\/(\d{4})\/(\d{2})\/?$/', $path, $matches)) {
    $_GET['theme'] = $matches[1];
    $_GET['preview_page'] = 'archive';
    $_GET['year'] = $matches[2];
    $_GET['month'] = $matches[3];
    require_once __DIR__ . '/theme-preview.php';
    return true;
}

// 主题预览：/preview/theme-id/archive
if (preg_match('/^\/preview\/([a-zA-Z0-9\-_]+)\/archive\/?$/', $path, $matches)) {
    $_GET['theme'] = $matches[1];
    $_GET['preview_page'] = 'archive';
    require_once __DIR__ . '/theme-preview.php';
    return true;
}

// 主题预览：首页 /preview/theme-id
if (preg_match('/^\/preview\/([a-zA-Z0-9\-_]+)\/?$/', $path, $matches)) {
    $_GET['theme'] = $matches[1];
    $_GET['preview_page'] = 'home';
    require_once __DIR__ . '/theme-preview.php';
    return true;
}

// 文章详情页：/article/slug -> article.php?slug=slug
if (preg_match('/^\/article\/([a-zA-Z0-9\-_]+)\/?$/', $path, $matches)) {
    $_GET['slug'] = $matches[1];
    try {
        require_once __DIR__ . '/article.php';
    } catch (Exception $e) {
        header('HTTP/1.0 500 Internal Server Error');
        echo 'Article page error: ' . $e->getMessage();
    }
    return true;
}

// 分类页面：/category/123 -> index.php?category=123
if (preg_match('/^\/category\/(\d+)\/?$/', $path, $matches)) {
    $_GET['category'] = $matches[1];
    require_once __DIR__ . '/index.php';
    return true;
}

// 分类页面（按slug）：/category/slug -> category.php?slug=slug
if (preg_match('/^\/category\/([a-zA-Z0-9\-_]+)\/?$/', $path, $matches)) {
    $_GET['slug'] = $matches[1];
    require_once __DIR__ . '/category.php';
    return true;
}

// 归档页面：/archive/2025/01 -> archive.php?year=2025&month=01
if (preg_match('/^\/archive\/(\d{4})\/(\d{2})\/?$/', $path, $matches)) {
    $_GET['year'] = $matches[1];
    $_GET['month'] = $matches[2];
    try {
        require_once __DIR__ . '/archive.php';
    } catch (Throwable $e) {
        header('HTTP/1.0 500 Internal Server Error');
        echo 'Archive page error: ' . $e->getMessage();
    }
    return true;
}

// 归档页面：/archive -> archive.php
if ($path === '/archive') {
    try {
        require_once __DIR__ . '/archive.php';
    } catch (Throwable $e) {
        header('HTTP/1.0 500 Internal Server Error');
        echo 'Archive page error: ' . $e->getMessage();
    }
    return true;
}

// 搜索页面：/search/keyword -> index.php?search=keyword
if (preg_match('/^\/search\/(.+?)\/?$/', $path, $matches)) {
    $_GET['search'] = urldecode($matches[1]);
    require_once __DIR__ . '/index.php';
    return true;
}

// 根目录
if ($path === '' || $path === '/') {
    require_once __DIR__ . '/index.php';
    return true;
}

// RSS订阅：/rss -> rss.php
if ($path === '/rss' || $path === '/feed') {
    require_once __DIR__ . '/rss.php';
    return true;
}

// 旧后台路径统一跳转到新路径
if ($path === $legacyAdminPath || strpos($path, $legacyAdminPath . '/') === 0) {
    $suffix = substr($requestPath, strlen($legacyAdminPath));
    $target = rtrim($adminBasePath, '/') . ($suffix === false ? '' : $suffix);
    if ($target === '') {
        $target = $adminBasePath . '/';
    }
    if ($queryString) {
        $target .= '?' . $queryString;
    }
    header('Location: ' . $target, true, 301);
    return true;
}

// API v1 路由
if ($requestPath === '/api/v1' || strpos($requestPath, '/api/v1/') === 0) {
    $_SERVER['API_REQUEST_PATH'] = substr($requestPath, strlen('/api/v1')) ?: '/';
    require_once __DIR__ . '/api/v1/index.php';
    return true;
}

// 新后台路径映射到物理 admin 目录
if ($requestPath === $adminBasePath || strpos($requestPath, $adminBasePath . '/') === 0) {
    $suffix = substr($requestPath, strlen($adminBasePath));
    $suffix = $suffix === false || $suffix === '' ? '/index.php' : $suffix;
    $adminFilePath = __DIR__ . '/admin' . $suffix;

    if (is_dir($adminFilePath)) {
        $adminFilePath = rtrim($adminFilePath, '/') . '/index.php';
    }

    if (file_exists($adminFilePath) && pathinfo($adminFilePath, PATHINFO_EXTENSION) === 'php') {
        require_once $adminFilePath;
        return true;
    }

    return false;
}

// 检查文件是否存在
$filePath = __DIR__ . $path;

// 如果是目录，尝试查找index.php
if (is_dir($filePath)) {
    $indexFile = $filePath . '/index.php';
    if (file_exists($indexFile)) {
        require_once $indexFile;
        return true;
    }
}

// 如果是PHP文件
if (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
    if (file_exists($filePath)) {
        require_once $filePath;
        return true;
    }
} else {
    // 尝试添加.php扩展名
    $phpFile = $filePath . '.php';
    if (file_exists($phpFile)) {
        require_once $phpFile;
        return true;
    }
}

// 文件不存在，返回false让PHP内置服务器处理
return false;
?>

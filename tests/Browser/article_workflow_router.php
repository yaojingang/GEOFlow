<?php

use Illuminate\Http\Request;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = realpath(dirname(__DIR__, 2).'/public'.rawurldecode((string) $path));
$public = realpath(dirname(__DIR__, 2).'/public');
if ($file && $public && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)
    && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
    return false;
}

$app = require __DIR__.'/article_workflow_bootstrap.php';
$app->handleRequest(Request::capture());

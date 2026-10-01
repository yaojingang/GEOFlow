<?php

namespace App\Services\Topics;

use Illuminate\Pagination\LengthAwarePaginator;

final class TopicPaginator extends LengthAwarePaginator
{
    public function url($page): string
    {
        $base = preg_replace('~/page/[0-9]+$~', '', $this->path());
        $url = $base.((int) $page > 1 ? '/page/'.max(1, (int) $page) : '');
        $query = $this->query;
        unset($query['page']);

        return $url.($query ? '?'.http_build_query($query) : '').$this->buildFragment();
    }
}

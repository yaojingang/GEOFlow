<?php

namespace App\Http\Requests\Admin;

use App\Models\Article;
use App\Models\DistributionChannel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssignHostedSiteArticleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $channel = $this->route('hostedSite');

        return $channel instanceof DistributionChannel && $channel->isHostedSite();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'article_id' => ['required', 'integer', 'exists:articles,id'],
        ];
    }

    /** @return array<int,callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $channel = $this->route('hostedSite');
            $articleId = (int) $this->input('article_id');
            if (! $channel instanceof DistributionChannel || $articleId <= 0) {
                return;
            }

            $belongsToChannel = Article::query()
                ->whereKey($articleId)
                ->whereHas('task.distributionChannels', fn ($query) => $query->whereKey((int) $channel->id))
                ->exists();
            if (! $belongsToChannel) {
                $validator->errors()->add('article_id', '文章任务必须已绑定当前托管站点。');
            }
        }];
    }
}

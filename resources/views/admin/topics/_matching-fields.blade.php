@php
$matchingPath=$matchingPath??'matching_rules';
$matchingName=$matchingName??'matching_rules';
$matchingValues=old($matchingPath,$matchingValues??[]);
$matchingId=str_replace('.','-',$matchingPath);
@endphp
<div class="space-y-4" data-topic-matching-rules>
 <p class="topic-help">基础匹配为标题词或相关词命中；每组必须词都需要命中，组内满足一个即可；排除词命中后跳过该文章。扫描当前站点与来源范围，最多保留 100 篇候选，选取前 40 篇供 AI 阅读。</p>
 <div><label for="{{ $matchingId }}-related">相关词，可选</label><textarea id="{{ $matchingId }}-related" name="{{ $matchingName }}[related_terms_text]" rows="2" placeholder="例如：内容运营，搜索优化">{{ $matchingValues['related_terms_text']??implode('，',$matchingValues['related_terms']??[]) }}</textarea><p class="topic-help mt-1">逗号或换行分隔，与专题标题词共同参与匹配。</p>@foreach($errors->getMessages() as $key=>$messages)@if(str_starts_with($key,$matchingPath.'.related_terms'))<p class="topic-error">{{ $messages[0] }}</p>@endif @endforeach</div>
 <div><label for="{{ $matchingId }}-required">必须词组，可选</label><textarea id="{{ $matchingId }}-required" name="{{ $matchingName }}[required_groups_text]" rows="3" placeholder="每行一组，例如：&#10;教程 | 指南&#10;实操 | 案例">{{ $matchingValues['required_groups_text']??implode("
",array_map(fn($group)=>implode(' | ',$group),$matchingValues['required_groups']??[])) }}</textarea><p class="topic-help mt-1">每行是一组，组内用逗号或 | 分隔。填写两行时，文章需同时满足两组。</p>@foreach($errors->getMessages() as $key=>$messages)@if(str_starts_with($key,$matchingPath.'.required_groups'))<p class="topic-error">{{ $messages[0] }}</p>@endif @endforeach</div>
 <div><label for="{{ $matchingId }}-excluded">排除词，可选</label><textarea id="{{ $matchingId }}-excluded" name="{{ $matchingName }}[excluded_terms_text]" rows="2" placeholder="逗号或换行分隔">{{ $matchingValues['excluded_terms_text']??implode('，',$matchingValues['excluded_terms']??[]) }}</textarea>@foreach($errors->getMessages() as $key=>$messages)@if(str_starts_with($key,$matchingPath.'.excluded_terms'))<p class="topic-error">{{ $messages[0] }}</p>@endif @endforeach</div>
 <p class="topic-help">命中按固定权重累计：标题 6 分，关键词和摘要各 3 分，正文 1 分。未命中词不扣分。每次生成保存本次规则，之后编辑用于下一次生成。</p>
 @error($matchingPath)<p class="topic-error">{{ $message }}</p>@enderror
</div>

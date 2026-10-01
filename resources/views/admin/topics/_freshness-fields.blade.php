@php
$freshnessPath = $freshnessPath ?? 'freshness';
$freshnessName = $freshnessName ?? 'freshness';
$freshnessValues = $freshnessValues ?? [];
$freshnessMode = old($freshnessPath.'.mode', $freshnessValues['mode'] ?? 'evergreen');
$freshnessOptions = ['evergreen'=>'常青主题', 'composed_at'=>'实际整理日期', 'annual'=>'年度覆盖', 'version'=>'产品版本', 'as_of'=>'核验截止日期', 'event'=>'活动时间窗口', 'rolling'=>'持续更新'];
if (in_array($freshnessMode,['none','monthly','recent'],true)) {
    $freshnessOptions[$freshnessMode] = ['none'=>'常青主题（旧设置）','monthly'=>'月份覆盖（旧设置）','recent'=>'近期覆盖（旧设置）'][$freshnessMode];
}
@endphp
<div class="grid gap-4 sm:grid-cols-2 mt-4">
    <div><label>时效模式</label><select name="{{ $freshnessName }}[mode]">@foreach($freshnessOptions as $key=>$label)<option value="{{ $key }}" @selected($freshnessMode===$key)>{{ $label }}</option>@endforeach</select></div>
    <div><label>时区（IANA）</label><input name="{{ $freshnessName }}[timezone]" value="{{ old($freshnessPath.'.timezone',$freshnessValues['timezone']??'Asia/Shanghai') }}" placeholder="Asia/Shanghai"></div>
    <div class="sm:col-span-2"><label>来源覆盖与核验说明</label><textarea rows="2" name="{{ $freshnessName }}[coverage_note]" placeholder="说明来源文章支持的年度、版本、日期范围或活动时间与状态依据。">{{ old($freshnessPath.'.coverage_note',$freshnessValues['coverage_note']??'') }}</textarea></div>
    <div><label>来源覆盖年度</label><input type="number" min="2000" max="2100" name="{{ $freshnessName }}[year]" value="{{ old($freshnessPath.'.year',$freshnessValues['year']??'') }}"></div>
    <div><label>已核验的产品版本</label><input name="{{ $freshnessName }}[version]" value="{{ old($freshnessPath.'.version',$freshnessValues['version']??'') }}" placeholder="例如 v2.5"></div>
    @if(in_array($freshnessMode,['monthly','recent'],true))<div><label>来源覆盖月份（旧设置）</label><input type="number" min="1" max="12" name="{{ $freshnessName }}[month]" value="{{ old($freshnessPath.'.month',$freshnessValues['month']??'') }}"></div>@endif
    @foreach(['effective_from'=>'内容有效期开始','effective_to'=>'内容有效期结束','public_from'=>'允许公开的起点','last_verified_at'=>'实际内容复核时间','next_review_at'=>'下次复核截止时间'] as $key=>$label)
    <div><label>{{ $label }}</label><input name="{{ $freshnessName }}[{{ $key }}]" value="{{ old($freshnessPath.'.'.$key,$freshnessValues[$key]??($key==='effective_to'?($freshnessValues['valid_until']??''):'')) }}" placeholder="2026-10-01 或 2026-10-01T09:00"><p class="topic-help mt-1">{{ $key==='effective_to'?'只填日期时，覆盖当地该日全天；填写时间时，到达该时刻即到期。':'按所选时区填写日期或时间，夏令时歧义时间需填写 UTC 偏移。' }}</p></div>
    @endforeach
    <div><label>到期处理</label><select name="{{ $freshnessName }}[expiry_action]">@foreach(['suppress_claims'=>'隐藏失效主张，保留历史来源','historical'=>'明确历史范围并保留来源'] as $key=>$label)<option value="{{ $key }}" @selected(old($freshnessPath.'.expiry_action',$freshnessValues['expiry_action']??'suppress_claims')===$key)>{{ $label }}</option>@endforeach</select></div>
    <div class="sm:col-span-2"><label>公开更新记录（持续更新模式必填）</label><textarea rows="2" name="{{ $freshnessName }}[public_updates]" placeholder="记录真实更新日期、范围和变化。">{{ old($freshnessPath.'.public_updates',$freshnessValues['public_updates']??'') }}</textarea></div>
    <div class="sm:col-span-2"><label>受控标题模板，可选</label><input name="{{ $freshnessName }}[title_template]" value="{{ old($freshnessPath.'.title_template',$freshnessValues['title_template']??'') }}" placeholder="留空使用对应模式默认标题"><p class="topic-help mt-1">可用变量：{title}、{year}、{month}、{version}、{as_of}、{composed_at}。专题名称和网址保持稳定。</p></div>
</div>
<p class="topic-help mt-3">整理日期来自本次实际内容编辑。年度、版本、截止日期在发布时固定。活动指南可提前公开，公开起点与活动时间分别保存。持续更新需要近 90 天真实复核、下一次复核和公开更新记录。</p>
@foreach($errors->getMessages() as $key=>$messages)@if(str_starts_with($key,$freshnessPath))<p class="topic-error">{{ $messages[0] }}</p>@endif @endforeach

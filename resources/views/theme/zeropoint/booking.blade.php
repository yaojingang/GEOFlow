@extends('theme.zeropoint.layout')

@section('content')
<section class="booking-page">
    <div class="shell booking-grid">
        <div class="booking-copy reveal"><p class="eyebrow">LIGHT APPOINTMENT</p><h1>先留一个合适的时间。</h1><p>请选择到店意向和期望时段。工作人员会人工联系核对，表单不会进行在线诊断，也不会自动确认预约。</p><ol><li><span>01</span>提交最小必要信息</li><li><span>02</span>工作人员人工联系</li><li><span>03</span>确认后获得正式预约</li></ol></div>
        <div class="booking-form-card">
            @if(session('message'))<div class="form-success" role="status">{{ session('message') }}</div>@endif
            @if($errors->any())<div class="form-errors" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
            <form method="post" action="{{ route('site.zeropoint.booking.submit') }}">
                @csrf
                <input type="hidden" name="_booking_token" value="{{ $bookingToken }}">
                @foreach(['utm_source','utm_medium','utm_campaign','utm_content'] as $utm)<input type="hidden" name="{{ $utm }}" value="{{ request()->query($utm, '') }}">@endforeach
                <div class="honeypot" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
                @foreach($leadForm->normalizedFields() as $field)
                    @php($fieldId = 'booking-'.$field['name'])
                    <div class="field {{ $field['type'] === 'checkbox' ? 'field-check' : '' }}">
                        @if($field['type'] !== 'checkbox')<label for="{{ $fieldId }}">{{ $field['label'] }} @if($field['required'])<span>*</span>@endif</label>@endif
                        @if($field['type'] === 'select')
                            <select id="{{ $fieldId }}" name="{{ $field['name'] }}" @required($field['required'])><option value="">请选择</option>@foreach($field['options'] as $option)<option value="{{ $option }}" @selected(old($field['name']) === $option)>{{ $option }}</option>@endforeach</select>
                        @elseif($field['type'] === 'checkbox')
                            <label for="{{ $fieldId }}"><input id="{{ $fieldId }}" type="checkbox" name="{{ $field['name'] }}" value="1" @checked(old($field['name'])) @required($field['required'])><span>{{ $field['options'][0] ?? $field['label'] }}</span></label>
                        @elseif($field['type'] === 'textarea')
                            <textarea id="{{ $fieldId }}" name="{{ $field['name'] }}" rows="3" maxlength="500" @required($field['required'])>{{ old($field['name']) }}</textarea>
                        @else
                            <input id="{{ $fieldId }}" type="{{ $field['type'] === 'phone' ? 'tel' : ($field['type'] === 'date' ? 'date' : 'text') }}" name="{{ $field['name'] }}" value="{{ old($field['name']) }}" @if($field['type'] === 'date') min="{{ now()->toDateString() }}" @endif @required($field['required'])>
                        @endif
                        @error($field['name'])<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                @endforeach
                <button class="button button-dark booking-submit" type="submit">提交预约意向</button>
                <p class="form-note">提交即表示你理解：本申请不构成医疗建议，且需工作人员再次确认。</p>
            </form>
        </div>
    </div>
</section>
@endsection

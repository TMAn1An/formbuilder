{{--
  Page renderer. $presented = PagePresenter::present(). The only raw outputs
  are the page stylesheet (validated design vars + scoped custom CSS with `<`
  escaped) and Paragraph/Custom-HTML content sanitized at render time.
--}}
<style>{!! $presented['styleSheet'] !!}</style>
<div class="pb-page" id="{{ $presented['wrapperId'] }}">
  <div class="pb-content">
    @foreach ($presented['blocks'] as $block)
      @include('formbuilder::pages._block', ['block' => $block])
    @endforeach
  </div>
</div>

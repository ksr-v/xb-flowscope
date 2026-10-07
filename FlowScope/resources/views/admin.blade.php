@php
    $flowScopeOriginalHtml = view()->file($__flowScopeOriginalPath, $__flowScopeOriginalData)->render();
    echo \Plugin\Flowscope\Services\AdminIntegration::inject($flowScopeOriginalHtml);
@endphp

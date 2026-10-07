<?php

declare(strict_types=1);

namespace Plugin\Flowscope\Services;

final class AdminCompatibility
{
    public const BUILD = 'index-CEIYH7i8.js';
    public const AUDITED_SHA256 = '0cfb12e1ec9afee9e439744671de2c14cb1af1b3118c291f0a87348492948666';

    // These signatures are exact structural boundaries used by the read-only
    // browser adapter. Unrelated third-party changes are deliberately allowed:
    // the whole-file hash is provenance, not a rejection rule.
    public const SIGNATURES = [
        'dashboard' => 'className:"grid gap-6",children:[Q.jsx(uqt,{}),Q.jsx($$t,{}),Q.jsx(eKt,{}),Q.jsx(UGt,{})]',
        'sidebar' => 'function Jlt({links:e,isCollapsed:t,className:n,closeNav:i})',
        'machineIdentity' => 's.reset({id:n.id,name:n.name??"",notes:n.notes??"",is_active:n.is_active??!0})',
        'machineCard' => 'function c3t({machine:e,t:t}){const n=g5t(e);',
        'machineForm' => 'Q.jsx(zy,{...s,children:Q.jsxs("form",{onSubmit:s.handleSubmit(async t=>{c(!0);try{const n=await TT(t)',
        'machineSchema' => 'const t3t=e=>py({id:dy().optional(),name:cy().min(1,e("form.nameError")),notes:cy().optional().default(""),is_active:uy().default(!0)});function n3t({refetch:e,dialogTrigger:t,defaultValues:n,type:i="add"})',
        'auth' => 'getKey(e){return`${this.prefixKey}${e}`.toUpperCase()}',
        'sidebarConfig' => 'id:"notice-management",title:"nav:noticeManagement",label:"",href:"/config/notice"',
    ];

    private readonly string $rootPath;

    public function __construct(?string $rootPath = null)
    {
        $this->rootPath = $rootPath ?? base_path();
    }

    public function inspect(): array
    {
        $result = [
            'supported' => false,
            'build' => null,
            'reason' => '',
            'signatures' => [],
        ];
        $manifestPath = $this->rootPath . '/public/assets/admin/manifest.json';
        if (!is_readable($manifestPath)) {
            $result['reason'] = 'Admin manifest is unavailable.';

            return $result;
        }

        $manifestContent = file_get_contents($manifestPath);
        $manifest = $manifestContent === false ? null : json_decode($manifestContent, true);
        $entry = is_array($manifest) ? ($manifest['index.html'] ?? null) : null;
        $entryFile = is_array($entry) ? ($entry['file'] ?? null) : null;
        if (!is_string($entryFile)) {
            $result['reason'] = 'Admin manifest has no supported entry.';

            return $result;
        }

        $result['build'] = basename($entryFile);
        if ($entryFile !== 'assets/' . self::BUILD) {
            $result['reason'] = 'This Admin build has not been verified by FlowScope.';

            return $result;
        }

        $bundlePath = $this->rootPath . '/public/assets/admin/' . $entryFile;
        if (!is_readable($bundlePath)) {
            $result['reason'] = 'Admin entry bundle is unavailable.';

            return $result;
        }

        $bundle = file_get_contents($bundlePath);
        if ($bundle === false) {
            $result['reason'] = 'Admin entry bundle could not be read.';

            return $result;
        }

        $failures = [];
        foreach (self::SIGNATURES as $name => $signature) {
            $count = substr_count($bundle, $signature);
            $result['signatures'][$name] = $count;
            if ($count !== 1) {
                $failures[] = $name;
            }
        }

        if ($failures !== []) {
            $result['reason'] = 'Admin compatibility signatures changed or are ambiguous: ' . implode(', ', $failures) . '.';

            return $result;
        }

        $result['supported'] = true;

        return $result;
    }
}

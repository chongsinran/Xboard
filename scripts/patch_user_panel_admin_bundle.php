<?php

$root = dirname(__DIR__);
$manifestPath = $root . '/public/assets/admin/manifest.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$bundleFile = $manifest['index.html']['file'] ?? null;
if (!$bundleFile) {
    throw new RuntimeException('Admin entry file is missing from manifest.json');
}

$bundlePath = $root . '/public/assets/admin/' . $bundleFile;
$bundle = (string) file_get_contents($bundlePath);

$replaceOnce = static function (string $source, string $marker, string $replacement, string $label): string {
    if (str_contains($source, $replacement)) {
        return $source;
    }
    $count = substr_count($source, $marker);
    if ($count !== 1) {
        throw new RuntimeException(sprintf('%s: expected one marker, found %d', $label, $count));
    }
    return str_replace($marker, $replacement, $source);
};

$bundle = $replaceOnce(
    $bundle,
    'force_https:cy().nullable().default(0),stop_register:',
    'force_https:cy().nullable().default(0),user_panel_enable:cy().nullable().default(0),stop_register:',
    'site validation schema'
);

$stopRegisterControl = 'Q.jsx(Uy,{control:s.control,name:"stop_register"';
$malformedUserPanelControl = 'Q.jsx(Uy,{control:s.control,name:"user_panel_enable",render:({field:t})=>Q.jsxs(Ky,{children:[Q.jsxs("div",{className:"space-y-0.5",children:[Q.jsx(Gy,{className:"text-base",children:e("site.form.userPanelEnable.label",{defaultValue:"Enable user panel"})}),Q.jsx(Yy,{children:e("site.form.userPanelEnable.description",{defaultValue:"Allow visitors to open the customer-facing web panel. Disabled by default."})})]}),Q.jsx(Zy,{children:Q.jsx(mGt,{checked:Boolean(t.value),onCheckedChange:e=>{t.onChange(Number(e)),c(s.getValues())}})})]}),';
$userPanelControl = substr($malformedUserPanelControl, 0, -1) . '}),';
if (str_contains($bundle, $malformedUserPanelControl)) {
    $bundle = str_replace($malformedUserPanelControl, $userPanelControl, $bundle);
}
if (!str_contains($bundle, 'name:"user_panel_enable"')) {
    $bundle = $replaceOnce($bundle, $stopRegisterControl, $userPanelControl . $stopRegisterControl, 'site toggle control');
}
file_put_contents($bundlePath, $bundle);

$localeValues = [
    'en-US.js' => ['Enable User Panel', 'Allow visitors to open the customer-facing web panel. Disabled by default.'],
    'zh-CN.js' => ['启用用户面板', '允许访客打开用户网页面板。默认关闭。'],
    'ru-RU.js' => ['Включить панель пользователя', 'Разрешить посетителям открывать пользовательскую веб-панель. По умолчанию отключено.'],
];

foreach ($localeValues as $filename => [$label, $description]) {
    $localePath = $root . '/public/assets/admin/locales/' . $filename;
    $locale = (string) file_get_contents($localePath);
    if (str_contains($locale, '"userPanelEnable"')) {
        continue;
    }
    $marker = '        "stopRegister": {';
    $count = substr_count($locale, $marker);
    if ($count !== 1) {
        throw new RuntimeException(sprintf('%s: expected one stopRegister marker, found %d', $filename, $count));
    }
    $block = "        \"userPanelEnable\": {\n"
        . '          "label": ' . json_encode($label, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ",\n"
        . '          "description": ' . json_encode($description, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        . "        },\n";
    file_put_contents($localePath, str_replace($marker, $block . $marker, $locale));
}

echo 'Patched user panel setting in public/assets/admin/' . $bundleFile . PHP_EOL;

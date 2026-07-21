<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifestPath = $root . '/public/assets/admin/manifest.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$bundleFile = $manifest['index.html']['file'] ?? null;

if (!is_string($bundleFile) || !preg_match('#^assets/index-[A-Za-z0-9_-]+\.js$#', $bundleFile)) {
    fwrite(STDERR, "Unable to locate the Xboard admin entry bundle.\n");
    exit(1);
}

$bundlePath = $root . '/public/assets/admin/' . $bundleFile;
$bundle = file_get_contents($bundlePath);
if ($bundle === false) {
    fwrite(STDERR, "Unable to read the Xboard admin bundle.\n");
    exit(1);
}

$schemaMarker = 'plan_change_enable:dy().nullable().default(!1)';
$freeSchema = 'free_node_enable:dy().nullable().default(!1),free_node_subscription_url:ly().nullable().default("")';
if (!str_contains($bundle, $freeSchema)) {
    if (!str_contains($bundle, $schemaMarker)) {
        fwrite(STDERR, "Unable to locate the subscription validation schema.\n");
        exit(1);
    }
    $bundle = str_replace($schemaMarker, $freeSchema . ',' . $schemaMarker, $bundle);
}

$defaultsMarker = 'plan_change_enable:!1';
if (!str_contains($bundle, 'free_node_enable:!1')) {
    $schemaEnd = strpos($bundle, '}),EGt={', strpos($bundle, $schemaMarker));
    $defaultPosition = $schemaEnd === false ? false : strpos($bundle, $defaultsMarker, $schemaEnd);
    if ($defaultPosition === false) {
        fwrite(STDERR, "Unable to locate the subscription default values.\n");
        exit(1);
    }
    $defaults = 'free_node_enable:!1,free_node_subscription_url:"",';
    $bundle = substr($bundle, 0, $defaultPosition) . $defaults . substr($bundle, $defaultPosition);
}

$firstFieldMarker = 'Q.jsx(Uy,{control:r.control,name:"plan_change_enable"';
$freeFieldMarker = 'Q.jsx(Uy,{control:r.control,name:"free_node_enable"';
if (!str_contains($bundle, $freeFieldMarker)) {
    $firstField = strpos($bundle, $firstFieldMarker);
    if ($firstField === false) {
        fwrite(STDERR, "Unable to locate the native subscription settings fields.\n");
        exit(1);
    }
    $fields = <<<'FIELDS'
Q.jsx(Uy,{control:r.control,name:"free_node_enable",render:({field:t})=>Q.jsxs(Ky,{children:[Q.jsx(Gy,{className:"text-base",children:e("subscribe.free_node_enable.title",{defaultValue:"免费节点"})}),Q.jsx(Yy,{children:e("subscribe.free_node_enable.description",{defaultValue:"开启后，BiLink 首页将显示免费节点入口。"})}),Q.jsx(Zy,{children:Q.jsx(mGt,{checked:t.value||!1,onCheckedChange:e=>{t.onChange(e),l(r.getValues())}})}),Q.jsx(Xy,{})]})}),Q.jsx(Uy,{control:r.control,name:"free_node_subscription_url",render:({field:t})=>Q.jsxs(Ky,{children:[Q.jsx(Gy,{className:"text-base",children:e("subscribe.free_node_subscription_url.title",{defaultValue:"免费节点订阅链接"})}),Q.jsx(Zy,{children:Q.jsx(Q6e,{placeholder:"https://example.com/subscription",...t,value:t.value||"",onChange:e=>{t.onChange(e),l(r.getValues())}})}),Q.jsx(Yy,{children:e("subscribe.free_node_subscription_url.description",{defaultValue:"用户点击 BiLink 首页的免费节点时将打开此链接。"})}),Q.jsx(Xy,{})]})}),
FIELDS;
    $bundle = substr($bundle, 0, $firstField) . $fields . substr($bundle, $firstField);
}

if (substr_count($bundle, $freeFieldMarker) !== 1) {
    fwrite(STDERR, "Expected exactly one free-node switch field.\n");
    exit(1);
}

if (file_put_contents($bundlePath, $bundle) === false) {
    fwrite(STDERR, "Unable to write the Xboard admin bundle.\n");
    exit(1);
}

$translations = [
    'zh-CN.js' => <<<'TEXT'
      "free_node_enable": {
        "title": "免费节点",
        "description": "开启后，BiLink 首页将显示免费节点入口。默认关闭。"
      },
      "free_node_subscription_url": {
        "title": "免费节点订阅链接",
        "description": "用户点击 BiLink 首页的免费节点时将打开此链接。"
      },
TEXT,
    'en-US.js' => <<<'TEXT'
      "free_node_enable": {
        "title": "Free Nodes",
        "description": "Show the Free Nodes entry on the BiLink home page. Disabled by default."
      },
      "free_node_subscription_url": {
        "title": "Free-node Subscription URL",
        "description": "This link opens when a user taps Free Nodes on the BiLink home page."
      },
TEXT,
    'ru-RU.js' => <<<'TEXT'
      "free_node_enable": {
        "title": "Бесплатные узлы",
        "description": "Показывать ссылку на бесплатные узлы на главной странице BiLink. По умолчанию выключено."
      },
      "free_node_subscription_url": {
        "title": "URL подписки на бесплатные узлы",
        "description": "Эта ссылка откроется при нажатии на бесплатные узлы в BiLink."
      },
TEXT,
];

foreach ($translations as $filename => $translation) {
    $localePath = $root . '/public/assets/admin/locales/' . $filename;
    $locale = file_get_contents($localePath);
    if ($locale === false) {
        fwrite(STDERR, "Unable to read {$filename}.\n");
        exit(1);
    }
    $subscribeStart = strpos($locale, '    "subscribe": {');
    $titlePosition = $subscribeStart === false ? false : strpos($locale, '"title":', $subscribeStart);
    $titleEnd = $titlePosition === false ? false : strpos($locale, "\n", $titlePosition);
    if ($subscribeStart === false || $titleEnd === false) {
        fwrite(STDERR, "Unable to locate subscription translations in {$filename}.\n");
        exit(1);
    }
    if (!str_contains(substr($locale, $subscribeStart, 2500), '"free_node_enable"')) {
        $locale = substr($locale, 0, $titleEnd + 1) . $translation . substr($locale, $titleEnd + 1);
        if (file_put_contents($localePath, $locale) === false) {
            fwrite(STDERR, "Unable to write {$filename}.\n");
            exit(1);
        }
    }
}

fwrite(STDOUT, "Native free-node subscription settings are ready.\n");

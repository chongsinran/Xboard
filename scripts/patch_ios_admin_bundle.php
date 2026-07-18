<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifestPath = $root . '/public/assets/admin/manifest.json';
$manifestContents = file_get_contents($manifestPath);
if ($manifestContents === false) {
    fwrite(STDERR, "Unable to read the Xboard admin manifest. Run git submodule update --init --recursive first.\n");
    exit(1);
}

$manifest = json_decode($manifestContents, true);
$bundleFile = $manifest['index.html']['file'] ?? null;
if (!is_string($bundleFile) || !preg_match('#^assets/index-[A-Za-z0-9_-]+\.js$#', $bundleFile)) {
    fwrite(STDERR, "Unable to locate the Xboard admin entry bundle in manifest.json.\n");
    exit(1);
}

$bundlePath = $root . '/public/assets/admin/' . $bundleFile;
$bundle = file_get_contents($bundlePath);
if ($bundle === false) {
    fwrite(STDERR, "Unable to read the Xboard admin bundle: {$bundleFile}\n");
    exit(1);
}

$bundle = str_replace(
    'macos_download_url:ly().nullable(),android_version:ly().nullable()',
    'macos_download_url:ly().nullable(),ios_version:ly().nullable(),ios_download_url:ly().nullable(),android_version:ly().nullable()',
    $bundle
);
$bundle = str_replace(
    'macos_download_url:"",android_version:""',
    'macos_download_url:"",ios_version:"",ios_download_url:"",android_version:""',
    $bundle
);

$iosMarker = 'Q.jsx(Uy,{control:r.control,name:"ios_version"';
$androidMarker = 'Q.jsx(Uy,{control:r.control,name:"android_version"';
$iosStarts = [];
$offset = 0;
while (($position = strpos($bundle, $iosMarker, $offset)) !== false) {
    $iosStarts[] = $position;
    $offset = $position + strlen($iosMarker);
}

if (count($iosStarts) > 1) {
    $androidStart = strpos($bundle, $androidMarker, end($iosStarts));
    if ($androidStart === false) {
        fwrite(STDERR, "Unable to locate Android fields after duplicate iOS fields.\n");
        exit(1);
    }
    $firstIosBlock = substr(
        $bundle,
        $iosStarts[0],
        $iosStarts[1] - $iosStarts[0] - 1
    );
    $bundle = substr($bundle, 0, $iosStarts[0])
        . $firstIosBlock
        . ','
        . substr($bundle, $androidStart);
}

if (strpos($bundle, $iosMarker) === false) {
    $androidStart = strpos($bundle, $androidMarker);
    $androidEnd = $androidStart === false
        ? false
        : strpos($bundle, ',t&&Q.jsx("div"', $androidStart);
    if ($androidStart === false || $androidEnd === false) {
        fwrite(STDERR, "Unable to locate native Android APP settings fields.\n");
        exit(1);
    }
    $androidFields = substr($bundle, $androidStart, $androidEnd - $androidStart);
    $iosFields = str_replace('android', 'ios', $androidFields);
    $bundle = substr($bundle, 0, $androidStart)
        . $iosFields
        . ','
        . substr($bundle, $androidStart);
}

$bundle = str_replace(
    [
        'e("app.ios.version.title")',
        'e("app.ios.version.description")',
        'e("app.ios.download.title")',
        'e("app.ios.download.description")',
    ],
    [
        'e("app.ios.version.title",{defaultValue:"iOS版本"})',
        'e("app.ios.version.description",{defaultValue:"iPhone和iPad客户端当前版本号"})',
        'e("app.ios.download.title",{defaultValue:"iOS下载地址"})',
        'e("app.ios.download.description",{defaultValue:"App Store、TestFlight或企业分发链接"})',
    ],
    $bundle
);

if (file_put_contents($bundlePath, $bundle) === false) {
    fwrite(STDERR, "Unable to write the Xboard admin bundle.\n");
    exit(1);
}

$localeUpdates = [
    'zh-CN.js' => <<<'TRANSLATION'
      "ios": {
        "version": {
          "title": "iOS版本",
          "description": "iPhone和iPad客户端当前版本号"
        },
        "download": {
          "title": "iOS下载地址",
          "description": "App Store、TestFlight或企业分发链接"
        }
      },

TRANSLATION,
    'en-US.js' => <<<'TRANSLATION'
      "ios": {
        "version": {
          "title": "iOS Version",
          "description": "Current version number of the iPhone and iPad client"
        },
        "download": {
          "title": "iOS Download URL",
          "description": "App Store, TestFlight, or enterprise distribution link"
        }
      },

TRANSLATION,
];

foreach ($localeUpdates as $filename => $translation) {
    $localePath = $root . '/public/assets/admin/locales/' . $filename;
    $locale = file_get_contents($localePath);
    if ($locale === false) {
        fwrite(STDERR, "Unable to read {$filename}.\n");
        exit(1);
    }
    $appStart = strpos($locale, '    "app": {');
    $androidStart = $appStart === false
        ? false
        : strpos($locale, '      "android": {', $appStart);
    $iosStart = $appStart === false
        ? false
        : strpos($locale, '      "ios": {', $appStart);
    if ($appStart === false || $androidStart === false) {
        fwrite(STDERR, "Unable to locate APP translations in {$filename}.\n");
        exit(1);
    }
    if ($iosStart === false || $iosStart > $androidStart) {
        $locale = substr($locale, 0, $androidStart)
            . $translation
            . substr($locale, $androidStart);
        if (file_put_contents($localePath, $locale) === false) {
            fwrite(STDERR, "Unable to write {$filename}.\n");
            exit(1);
        }
    }
}

fwrite(STDOUT, "Native iOS APP settings fields and translations are ready.\n");

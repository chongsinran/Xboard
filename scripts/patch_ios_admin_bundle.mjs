import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const bundlePath = path.join(
  root,
  "public/assets/admin/assets/index-DuZigjjp.js",
);
let bundle = fs.readFileSync(bundlePath, "utf8");

if (!bundle.includes("ios_version:ly().nullable()")) {
  bundle = bundle.replace(
    "macos_download_url:ly().nullable(),android_version:ly().nullable()",
    "macos_download_url:ly().nullable(),ios_version:ly().nullable(),ios_download_url:ly().nullable(),android_version:ly().nullable()",
  );
}

if (!bundle.includes('ios_version:""')) {
  bundle = bundle.replace(
    'macos_download_url:"",android_version:""',
    'macos_download_url:"",ios_version:"",ios_download_url:"",android_version:""',
  );
}

if (!bundle.includes('e("app.ios.version.title")')) {
  const androidStart = bundle.indexOf(
    'Q.jsx(Uy,{control:r.control,name:"android_version"',
  );
  const androidEnd = bundle.indexOf(',t&&Q.jsx("div"', androidStart);
  if (androidStart === -1 || androidEnd === -1) {
    throw new Error("Unable to locate the native Android APP settings fields.");
  }
  const androidFields = bundle.slice(androidStart, androidEnd);
  const iosFields = androidFields.replaceAll("android", "ios");
  bundle =
    bundle.slice(0, androidStart) +
    iosFields +
    "," +
    bundle.slice(androidStart);
}

fs.writeFileSync(bundlePath, bundle);

const localeUpdates = {
  "zh-CN.js": `      "ios": {
        "version": {
          "title": "iOS版本",
          "description": "iPhone和iPad客户端当前版本号"
        },
        "download": {
          "title": "iOS下载地址",
          "description": "App Store、TestFlight或企业分发链接"
        }
      },
`,
  "en-US.js": `      "ios": {
        "version": {
          "title": "iOS Version",
          "description": "Current version number of the iPhone and iPad client"
        },
        "download": {
          "title": "iOS Download URL",
          "description": "App Store, TestFlight, or enterprise distribution link"
        }
      },
`,
};

for (const [filename, iosTranslation] of Object.entries(localeUpdates)) {
  const localePath = path.join(root, "public/assets/admin/locales", filename);
  let locale = fs.readFileSync(localePath, "utf8");
  const appStart = locale.indexOf('    "app": {');
  const androidStart = locale.indexOf('      "android": {', appStart);
  const iosStart = locale.indexOf('      "ios": {', appStart);
  if (appStart === -1 || androidStart === -1) {
    throw new Error(`Unable to locate APP translations in ${filename}.`);
  }
  if (iosStart === -1 || iosStart > androidStart) {
    locale =
      locale.slice(0, androidStart) +
      iosTranslation +
      locale.slice(androidStart);
    fs.writeFileSync(localePath, locale);
  }
}

console.log(
  "Native iOS APP settings fields and translations are present in the admin assets.",
);

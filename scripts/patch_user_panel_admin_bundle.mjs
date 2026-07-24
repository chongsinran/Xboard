import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const manifest = JSON.parse(fs.readFileSync(path.join(root, "public/assets/admin/manifest.json"), "utf8"));
const entryFile = manifest["index.html"]?.file;
if (!entryFile) throw new Error("Admin entry file is missing from manifest.json");

const bundlePath = path.join(root, "public/assets/admin", entryFile);
let bundle = fs.readFileSync(bundlePath, "utf8");

const replaceOnce = (source, marker, replacement, label) => {
  if (source.includes(replacement)) return source;
  const count = source.split(marker).length - 1;
  if (count !== 1) throw new Error(`${label}: expected one marker, found ${count}`);
  return source.replace(marker, replacement);
};

bundle = replaceOnce(
  bundle,
  "force_https:cy().nullable().default(0),stop_register:",
  "force_https:cy().nullable().default(0),user_panel_enable:cy().nullable().default(0),stop_register:",
  "site validation schema",
);

const stopRegisterControl = 'Q.jsx(Uy,{control:s.control,name:"stop_register"';
const malformedUserPanelControl = 'Q.jsx(Uy,{control:s.control,name:"user_panel_enable",render:({field:t})=>Q.jsxs(Ky,{children:[Q.jsxs("div",{className:"space-y-0.5",children:[Q.jsx(Gy,{className:"text-base",children:e("site.form.userPanelEnable.label",{defaultValue:"Enable user panel"})}),Q.jsx(Yy,{children:e("site.form.userPanelEnable.description",{defaultValue:"Allow visitors to open the customer-facing web panel. Disabled by default."})})]}),Q.jsx(Zy,{children:Q.jsx(mGt,{checked:Boolean(t.value),onCheckedChange:e=>{t.onChange(Number(e)),c(s.getValues())}})})]}),';
const userPanelControl = malformedUserPanelControl.slice(0, -1) + '}),';
if (bundle.includes(malformedUserPanelControl)) {
  bundle = bundle.replace(malformedUserPanelControl, userPanelControl);
}
if (!bundle.includes('name:"user_panel_enable"')) {
  bundle = replaceOnce(bundle, stopRegisterControl, userPanelControl + stopRegisterControl, "site toggle control");
}

fs.writeFileSync(bundlePath, bundle);

const localeValues = {
  "en-US.js": {
    label: "Enable User Panel",
    description: "Allow visitors to open the customer-facing web panel. Disabled by default.",
  },
  "zh-CN.js": {
    label: "启用用户面板",
    description: "允许访客打开用户网页面板。默认关闭。",
  },
  "ru-RU.js": {
    label: "Включить панель пользователя",
    description: "Разрешить посетителям открывать пользовательскую веб-панель. По умолчанию отключено.",
  },
};

for (const [filename, value] of Object.entries(localeValues)) {
  const localePath = path.join(root, "public/assets/admin/locales", filename);
  let locale = fs.readFileSync(localePath, "utf8");
  if (!locale.includes('"userPanelEnable"')) {
    const marker = '        "stopRegister": {';
    const count = locale.split(marker).length - 1;
    if (count !== 1) throw new Error(`${filename}: expected one stopRegister marker, found ${count}`);
    const block = `        "userPanelEnable": {\n          "label": ${JSON.stringify(value.label)},\n          "description": ${JSON.stringify(value.description)}\n        },\n`;
    locale = locale.replace(marker, block + marker);
    fs.writeFileSync(localePath, locale);
  }
}

console.log(`Patched user panel setting in ${path.relative(root, bundlePath)}`);

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const manifestPath = path.join(root, "public/assets/admin/manifest.json");
const manifest = JSON.parse(fs.readFileSync(manifestPath, "utf8"));
const entryFile = manifest?.["index.html"]?.file;
if (!/^assets\/index-[A-Za-z0-9_-]+\.js$/.test(entryFile ?? "")) {
  throw new Error("Unable to locate the Xboard admin entry bundle.");
}

const bundlePath = path.join(root, "public/assets/admin", entryFile);
let bundle = fs.readFileSync(bundlePath, "utf8");

const schemaMarker = "plan_change_enable:dy().nullable().default(!1)";
if (!bundle.includes("free_node_enable:dy().nullable().default(!1)")) {
  if (!bundle.includes(schemaMarker)) {
    throw new Error("Unable to locate the subscription validation schema.");
  }
  bundle = bundle.replace(
    schemaMarker,
    `free_node_enable:dy().nullable().default(!1),free_node_subscription_url:ly().nullable().default(""),${schemaMarker}`,
  );
}

const defaultsMarker = "plan_change_enable:!1";
if (!bundle.includes("free_node_enable:!1")) {
  const schemaEnd = bundle.indexOf("}),EGt={", bundle.indexOf(schemaMarker));
  if (schemaEnd === -1 || !bundle.slice(schemaEnd, schemaEnd + 500).includes(defaultsMarker)) {
    throw new Error("Unable to locate the subscription default values.");
  }
  const defaultPosition = bundle.indexOf(defaultsMarker, schemaEnd);
  bundle =
    bundle.slice(0, defaultPosition) +
    `free_node_enable:!1,free_node_subscription_url:"",` +
    bundle.slice(defaultPosition);
}

const firstFieldMarker = 'Q.jsx(Uy,{control:r.control,name:"plan_change_enable"';
const freeNodeFieldMarker = 'Q.jsx(Uy,{control:r.control,name:"free_node_enable"';
if (!bundle.includes(freeNodeFieldMarker)) {
  const firstField = bundle.indexOf(firstFieldMarker);
  if (firstField === -1) {
    throw new Error("Unable to locate the native subscription settings fields.");
  }
  const fields = `Q.jsx(Uy,{control:r.control,name:"free_node_enable",render:({field:t})=>Q.jsxs(Ky,{children:[Q.jsx(Gy,{className:"text-base",children:e("subscribe.free_node_enable.title",{defaultValue:"免费节点"})}),Q.jsx(Yy,{children:e("subscribe.free_node_enable.description",{defaultValue:"开启后，BiLink 首页将显示免费节点入口。"})}),Q.jsx(Zy,{children:Q.jsx(mGt,{checked:t.value||!1,onCheckedChange:e=>{t.onChange(e),l(r.getValues())}})}),Q.jsx(Xy,{})]})}),Q.jsx(Uy,{control:r.control,name:"free_node_subscription_url",render:({field:t})=>Q.jsxs(Ky,{children:[Q.jsx(Gy,{className:"text-base",children:e("subscribe.free_node_subscription_url.title",{defaultValue:"免费节点订阅链接"})}),Q.jsx(Zy,{children:Q.jsx(Q6e,{placeholder:"https://example.com/subscription",...t,value:t.value||"",onChange:e=>{t.onChange(e),l(r.getValues())}})}),Q.jsx(Yy,{children:e("subscribe.free_node_subscription_url.description",{defaultValue:"用户点击 BiLink 首页的免费节点时将打开此链接。"})}),Q.jsx(Xy,{})]})}),`;
  bundle = bundle.slice(0, firstField) + fields + bundle.slice(firstField);
}

const fieldCount = bundle.split(freeNodeFieldMarker).length - 1;
if (fieldCount !== 1) {
  throw new Error(`Expected one free-node switch field, found ${fieldCount}.`);
}
fs.writeFileSync(bundlePath, bundle);

const translations = {
  "zh-CN.js": `      "free_node_enable": {
        "title": "免费节点",
        "description": "开启后，BiLink 首页将显示免费节点入口。默认关闭。"
      },
      "free_node_subscription_url": {
        "title": "免费节点订阅链接",
        "description": "用户点击 BiLink 首页的免费节点时将打开此链接。"
      },
`,
  "en-US.js": `      "free_node_enable": {
        "title": "Free Nodes",
        "description": "Show the Free Nodes entry on the BiLink home page. Disabled by default."
      },
      "free_node_subscription_url": {
        "title": "Free-node Subscription URL",
        "description": "This link opens when a user taps Free Nodes on the BiLink home page."
      },
`,
  "ru-RU.js": `      "free_node_enable": {
        "title": "Бесплатные узлы",
        "description": "Показывать ссылку на бесплатные узлы на главной странице BiLink. По умолчанию выключено."
      },
      "free_node_subscription_url": {
        "title": "URL подписки на бесплатные узлы",
        "description": "Эта ссылка откроется при нажатии на бесплатные узлы в BiLink."
      },
`,
};

for (const [filename, translation] of Object.entries(translations)) {
  const localePath = path.join(root, "public/assets/admin/locales", filename);
  let locale = fs.readFileSync(localePath, "utf8");
  const subscribeStart = locale.indexOf('    "subscribe": {');
  if (subscribeStart === -1) {
    throw new Error(`Unable to locate subscription translations in ${filename}.`);
  }
  const titleEnd = locale.indexOf("\n", locale.indexOf('"title":', subscribeStart));
  if (titleEnd === -1) {
    throw new Error(`Unable to locate subscription title in ${filename}.`);
  }
  if (!locale.slice(subscribeStart, subscribeStart + 2500).includes('"free_node_enable"')) {
    locale = locale.slice(0, titleEnd + 1) + translation + locale.slice(titleEnd + 1);
    fs.writeFileSync(localePath, locale);
  }
}

console.log("Native free-node subscription settings are present in the admin assets.");

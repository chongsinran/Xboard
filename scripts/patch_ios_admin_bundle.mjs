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
console.log("Native iOS APP settings fields are present in the admin bundle.");

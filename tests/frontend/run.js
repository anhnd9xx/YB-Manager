// Harness: stub browser env + nap NGUYEN BAN assets/js/app.js + chay test files.
// Dung: node tests/frontend/run.js [testfile...] (mac dinh: *.test.js)
// Khong them framework (Node co san). Khong copy logic app — test code that.
const fs = require('fs');
const path = require('path');
const ROOT = path.join(__dirname, '..', '..');

const __els = {};
function makeEl(id) {
  return { id, innerHTML: '', textContent: '', value: '', checked: false, disabled: false,
    dataset: {}, style: {}, classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } },
    addEventListener() {}, appendChild() {}, querySelectorAll() { return []; },
    closest() { return null; }, click() {}, focus() {}, remove() {} };
}
const document = { addEventListener() {}, querySelectorAll() { return []; },
  getElementById(id) { if (!__els[id]) __els[id] = makeEl(id); return __els[id]; },
  createElement() { return makeEl('x'); }, hidden: false, body: makeEl('body') };
const window = { __ytmBulkOp: false };
const localStorage = { getItem() { return null; }, setItem() {} };
const toastLog = [];
const fetchLog = [];
let fetchHandler = null;
global.fetch = async (url, opts) => {
  fetchLog.push([String(url), opts && opts.body ? JSON.parse(opts.body) : null]);
  if (fetchHandler) return fetchHandler(url, opts);
  return { json: async () => ({ ok: true, data: {} }) };
};
global.__testList = [];
global.test = (name, fn) => global.__testList.push({ name, fn });
global.assertEq = (a, b, msg) => {
  if (JSON.stringify(a) !== JSON.stringify(b)) {
    throw new Error((msg || 'assertEq') + ' expected=' + JSON.stringify(b) + ' got=' + JSON.stringify(a));
  }
};
global.assert = (c, msg) => { if (!c) throw new Error(msg || 'assert'); };

const appSrc = fs.readFileSync(path.join(ROOT, 'assets', 'js', 'app.js'), 'utf8');
const files = process.argv.slice(2);
const testFiles = files.length ? files : fs.readdirSync(__dirname)
  .filter(f => f.endsWith('.test.js')).map(f => path.join(__dirname, f));
let testSrc = '';
for (const f of testFiles) testSrc += '\n' + fs.readFileSync(f, 'utf8');

new Function('document', 'window', 'localStorage', 'toastLog', 'fetchLog',
  appSrc + '\n' + testSrc + '\n' +
  '// app.js dinh nghia lai toast/refreshAll -> boc lai de thu thap\n' +
  'toast = (m, t) => { toastLog.push([m, t]); };\n' +
  'refreshAll = () => { global.__refreshCount = (global.__refreshCount || 0) + 1; };\n' +
  '(async () => {\n' +
  '  let pass = 0, fail = 0;\n' +
  '  for (const t of global.__testList) {\n' +
  '    // reset giua cac test\n' +
  '    toastLog.length = 0; fetchLog.length = 0;\n' +
  '    try { await t.fn(); pass++; console.log("PASS " + t.name); }\n' +
  '    catch (e) { fail++; console.log("FAIL " + t.name + " :: " + (e && e.message)); }\n' +
  '  }\n' +
  '  console.log("FRONTEND: " + pass + " passed, " + fail + " failed");\n' +
  '  process.exit(fail ? 1 : 0);\n' +
  '})();'
)(document, window, localStorage, toastLog, fetchLog);

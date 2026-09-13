const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { createRequire } = require('node:module');
const root = path.resolve(__dirname, '..');
function files(dir) { return fs.readdirSync(dir, { withFileTypes: true }).flatMap(item => item.isDirectory() ? files(path.join(dir, item.name)) : [path.join(dir, item.name)]); }

test('all pages, component references, navigation paths and tab assets exist', () => {
  const app = JSON.parse(fs.readFileSync(path.join(root, 'app.json')));
  for (const page of app.pages) for (const ext of ['js', 'json', 'wxml', 'wxss']) assert.ok(fs.existsSync(path.join(root, page + '.' + ext)), page + '.' + ext);
  for (const tab of app.tabBar.list) for (const asset of [tab.iconPath, tab.selectedIconPath]) assert.ok(fs.existsSync(path.join(root, asset)), asset);
  for (const file of files(path.join(root, 'pages'))) {
    const text = fs.readFileSync(file, 'utf8');
    if (file.endsWith('.json')) for (const component of Object.values(JSON.parse(text).usingComponents || {})) {
      for (const ext of ['js', 'json', 'wxml', 'wxss']) assert.ok(fs.existsSync(path.join(root, component.slice(1) + '.' + ext)), component + '.' + ext);
    }
    if (file.endsWith('.js')) for (const match of text.matchAll(/['"]\/(pages\/[\w/-]+)(?:\?|['"])/g)) assert.ok(app.pages.includes(match[1]), 'Unregistered page: ' + match[1]);
    if (file.endsWith('.wxml')) for (const match of text.matchAll(/src="([^"{]+)"/g)) assert.ok(fs.existsSync(path.resolve(path.dirname(file), match[1])), 'Missing local image: ' + match[1]);
  }
});

test('every WXML handler resolves to its page or component method', () => {
  for (const file of [...files(path.join(root, 'pages')), ...files(path.join(root, 'components'))].filter(file => file.endsWith('.wxml'))) {
    const js = file.replace(/\.wxml$/, '.js');
    let definition;
    vm.runInNewContext(fs.readFileSync(js, 'utf8'), { Page: value => { definition = value; }, Component: value => { definition = value.methods || {}; }, require: createRequire(js), console }, { filename: js });
    assert.ok(definition, js + ' must register a page or component');
    for (const match of fs.readFileSync(file, 'utf8').matchAll(/(?:bind|catch):?[\w-]+\s*=\s*["']([\w]+)["']/g)) assert.equal(typeof definition[match[1]], 'function', file + ': missing handler ' + match[1]);
  }
});

test('client production code is REST-only, parses, and contains no unfinished page copy', () => {
  for (const dir of ['pages', 'components', 'services', 'utils']) for (const file of files(path.join(root, dir))) {
    const source = fs.readFileSync(file, 'utf8');
    if (file.endsWith('.js')) {
      new vm.Script(source, { filename: file });
      assert.doesNotMatch(source, /(?:require\s*\(|from\s+)[^\n]*(?:backend|database|\.php)/i, file);
    }
    assert.doesNotMatch(source, /将在这里|即将上线|敬请期待|TODO|FIXME|mockData|sampleData|示例菜谱|占位按钮/, file);
  }
});

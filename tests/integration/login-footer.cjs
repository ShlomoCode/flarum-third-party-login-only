const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class LogInModal {}
class OtherComponent {}
let forgotPasswordLink = '';
const compat = new Proxy({}, {
  get: (_, key) => {
    if (key === 'extend') return {
      extend() {},
      override(prototype, method, implementation) { prototype[method] = implementation; },
    };
    return key.endsWith('/LogInModal') ? LogInModal : OtherComponent;
  },
});
const app = {
  initializers: { add: (_, initialize) => initialize(app) },
  forum: { attribute: () => forgotPasswordLink },
  translator: { trans: () => 'Forgot password' },
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../js/dist/forum.js'), 'utf8'), {
  flarum: { core: { compat } }, app, module: { exports: {} },
  m: (tag, attrs, ...children) => ({ tag, attrs, children }),
});

// Private Facade 0.1.16 indexes and edits the inherited modal footer array.
for (const link of ['', undefined, 'https://example.test/recovery']) {
  forgotPasswordLink = link;
  const footer = new LogInModal().footer();
  assert.ok(Array.isArray(footer), 'login footer must preserve the native array contract');
  const headerContent = footer[0];
  delete footer[0];
  footer[1] = 'signup-route-switcher';
  assert.equal(footer[1], 'signup-route-switcher');
  if (link) assert.equal(headerContent.children[0].attrs.href, link);
  else assert.equal(headerContent, undefined);
}
console.log('PASS: compiled login footer works with Private Facade and optional recovery links');

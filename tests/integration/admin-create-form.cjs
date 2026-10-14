const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class CreateUserModal {
  fields() {
    const entries = new Set(['username', 'email', 'password', 'emailConfirmation', 'useRandomPassword', 'submit', 'submitAndAdd', 'customField']);
    return { remove: key => entries.delete(key), entries };
  }
}
const extensionData = { registerSetting() { return this; } };
const app = {
  initializers: { add: (_, initialize) => initialize() },
  extensionData: { for: () => extensionData },
};
const compat = {
  'admin/app': app,
  'admin/components/CreateUserModal': CreateUserModal,
  'common/extend': {
    extend(prototype, method, implementation) {
      const original = prototype[method];
      prototype[method] = function (...args) {
        const result = original.apply(this, args);
        implementation.call(this, result, ...args);
        return result;
      };
    },
  },
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../js/dist/admin.js'), 'utf8'), {
  flarum: { core: { compat } }, module: { exports: {} },
  m: (tag, attrs, ...children) => ({ tag, attrs, children }),
});
assert.deepEqual([...new CreateUserModal().fields().entries], ['username', 'email', 'submit', 'submitAndAdd', 'customField']);
console.log('PASS: compiled admin form removes password and email-confirmation controls while preserving creation and extension fields');

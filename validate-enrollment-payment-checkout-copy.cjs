const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = process.env.GB_EP_PLUGIN_DIR;
if (!root) throw new Error('GB_EP_PLUGIN_DIR is required.');
const source = fs.readFileSync(path.join(root, 'assets', 'checkout.js'), 'utf8');

const ids = [
  'gb_ep_purchaser_is_student',
  'gb_ep_student_name',
  'gb_ep_student_email',
  'gb_ep_student_phone',
  'gb_ep_student_address_1',
  'gb_ep_student_city',
  'gb_ep_student_state',
  'gb_ep_student_postcode',
  'gb_ep_student_dob',
  'billing_first_name',
  'billing_last_name',
  'billing_email',
  'billing_phone',
  'billing_address_1',
  'billing_city',
  'billing_state',
  'billing_postcode',
  'billing_country',
];

const elements = Object.fromEntries(ids.map((id) => [id, {
  id,
  value: '',
  checked: false,
  classList: { toggle() {} },
  dispatchEvent() { throw new Error(`Unexpected per-field change event: ${id}`); },
}]));
const listeners = {};
const body = { id: 'body' };
const timers = new Map();
let nextTimer = 0;
let checkoutUpdates = 0;

const document = {
  readyState: 'complete',
  body,
  getElementById(id) { return elements[id] || null; },
  addEventListener(type, callback) { listeners[type] = callback; },
};

function jQuery(target) {
  return {
    trigger(eventName) {
      if (target === body && eventName === 'update_checkout') checkoutUpdates += 1;
    },
  };
}

const window = {
  jQuery,
  setTimeout(callback) {
    nextTimer += 1;
    timers.set(nextTimer, callback);
    return nextTimer;
  },
  clearTimeout(timer) { timers.delete(timer); },
};

vm.runInNewContext(source, { window, document, Event });

Object.assign(elements.gb_ep_student_name, { value: 'Gulf Breeze R4 Test Student' });
Object.assign(elements.gb_ep_student_email, { value: 'r4-student@example.invalid' });
Object.assign(elements.gb_ep_student_phone, { value: '9725550199' });
Object.assign(elements.gb_ep_student_address_1, { value: '100 Test Street' });
Object.assign(elements.gb_ep_student_city, { value: 'Carrollton' });
Object.assign(elements.gb_ep_student_state, { value: 'TX' });
Object.assign(elements.gb_ep_student_postcode, { value: '75007' });
elements.gb_ep_purchaser_is_student.checked = true;
listeners.change({ target: elements.gb_ep_purchaser_is_student });

if (elements.billing_first_name.value !== 'Gulf') throw new Error('First name was not copied.');
if (elements.billing_last_name.value !== 'Breeze R4 Test Student') throw new Error('Last name was not copied.');
if (elements.billing_email.value !== 'r4-student@example.invalid') throw new Error('Email was not copied.');
if (elements.billing_phone.value !== '9725550199') throw new Error('Phone was not copied.');
if (timers.size !== 1) throw new Error('Copy scheduled more than one checkout refresh.');

for (const callback of timers.values()) callback();
timers.clear();
if (checkoutUpdates !== 1) throw new Error('Initial copy did not produce exactly one checkout refresh.');

for (const value of ['r', 'r4', 'r4-final@example.invalid']) {
  elements.gb_ep_student_email.value = value;
  listeners.input({ target: elements.gb_ep_student_email });
}
if (elements.billing_email.value !== 'r4-final@example.invalid') throw new Error('Latest email was not preserved.');
if (timers.size !== 1) throw new Error('Rapid input was not debounced to one pending refresh.');
for (const callback of timers.values()) callback();
if (checkoutUpdates !== 2) throw new Error('Rapid input produced overlapping checkout refreshes.');

console.log('PASS purchaser-is-student copy preserves email and phone with one debounced refresh');

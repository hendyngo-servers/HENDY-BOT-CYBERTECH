const test = require('node:test');
const assert = require('node:assert/strict');
const { getBotToken, getAdminId } = require('./botConfig');

test('uses the preferred Vietnamese token names', () => {
  const env = { BOTCHINH_TOKEN: 'main-token', BOTPHU_TOKEN: 'side-token', ADMIN_ID: '123456' };
  assert.equal(getBotToken('primary', env), 'main-token');
  assert.equal(getBotToken('secondary', env), 'side-token');
  assert.equal(getAdminId('primary', env), '123456');
  assert.equal(getAdminId('secondary', env), '123456');
});

test('accepts BOT1_TOKEN and BOT2_TOKEN aliases', () => {
  const env = { BOT1_TOKEN: 'bot1', BOT2_TOKEN: 'bot2', ADMIN_ID: '123456', BOTPHU_ADMIN_ID: '654321' };
  assert.equal(getBotToken('primary', env), 'bot1');
  assert.equal(getBotToken('secondary', env), 'bot2');
  assert.equal(getAdminId('secondary', env), '654321');
});

test('keeps compatibility with the previous environment variable names', () => {
  const env = { BOT_TOKEN: 'legacy-main', BOT_SECOND_TOKEN: 'legacy-side', ADMIN_ID: '123456', BOT_SECOND_ADMIN_ID: '654321' };
  assert.equal(getBotToken('primary', env), 'legacy-main');
  assert.equal(getBotToken('secondary', env), 'legacy-side');
  assert.equal(getAdminId('secondary', env), '654321');
});

test('prefers the new names when both new and legacy variables exist', () => {
  const env = { BOTCHINH_TOKEN: 'new-main', BOT_TOKEN: 'old-main', BOTPHU_TOKEN: 'new-side', BOT_SECOND_TOKEN: 'old-side', ADMIN_ID: '123456' };
  assert.equal(getBotToken('primary', env), 'new-main');
  assert.equal(getBotToken('secondary', env), 'new-side');
});

function firstConfigured(env, keys) {
  for (const key of keys) {
    const value = String(env[key] || '').trim();
    if (value) return value;
  }
  return '';
}

function getBotToken(botKey, env = process.env) {
  if (botKey === 'secondary') {
    return firstConfigured(env, ['BOTPHU_TOKEN', 'BOT2_TOKEN', 'BOT_SECOND_TOKEN']);
  }
  return firstConfigured(env, ['BOTCHINH_TOKEN', 'BOT1_TOKEN', 'BOT_TOKEN']);
}

function getAdminId(botKey, env = process.env) {
  if (botKey === 'secondary') {
    return firstConfigured(env, ['BOTPHU_ADMIN_ID', 'BOT_SECOND_ADMIN_ID', 'ADMIN_ID']);
  }
  return firstConfigured(env, ['ADMIN_ID']);
}

module.exports = { getBotToken, getAdminId };

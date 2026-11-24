const assert = require('node:assert/strict');
const express = require('express');
const http = require('http');

process.env.ADMIN_USERNAME = 'admin';
process.env.ADMIN_PASSWORD = 'test-password';

const authRoutes = require('../src/routes/authRoutes');
const app = express();
app.use(express.json());
app.use('/auth', authRoutes);

const server = http.createServer(app);
const originalDateNow = Date.now;
let now = originalDateNow();

async function login(username, password) {
  const response = await fetch(`http://127.0.0.1:${server.address().port}/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password })
  });
  return response.status;
}

async function run() {
  Date.now = () => now;
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

  try {
    for (let attempt = 1; attempt < 5; attempt++) {
      assert.equal(await login('admin', 'wrong-password'), 401);
    }
    assert.equal(await login('admin', 'wrong-password'), 429);

    now += 5 * 60 * 1000;
    assert.equal(await login('admin', 'wrong-password'), 401);
    assert.equal(await login('admin', 'test-password'), 200);
    console.log('Auth lockout expiry test passed.');
  } finally {
    Date.now = originalDateNow;
    await new Promise((resolve, reject) => {
      server.close((error) => error ? reject(error) : resolve());
    });
  }
}

run().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

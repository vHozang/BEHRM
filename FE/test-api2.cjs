const axios = require('axios');

async function test() {
  try {
    const login = await axios.post('http://localhost:80/api/v1/auth/login', {
      company_email: 'admin@company.com',
      password: 'password'
    });
    const token = login.data.data.access_token;
    
    const depts = await axios.get('http://localhost:80/api/v1/departments', {
      headers: { Authorization: `Bearer ${token}` }
    });
    const payload = depts.data.data;
    console.log('payload.pagination:', payload.pagination);
    console.log('Is payload object?', typeof payload === 'object');
    console.log('Is payload.items array?', Array.isArray(payload.items));
    console.log('Does it match Case 1?', Boolean(payload && typeof payload === 'object' && Array.isArray(payload.items) && payload.pagination));
  } catch (err) {
    console.error(err.response ? err.response.data : err.message);
  }
}
test();

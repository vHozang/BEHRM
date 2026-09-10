const axios = require('axios');

async function test() {
  try {
    const login = await axios.post('http://localhost:80/api/v1/auth/login', {
      company_email: 'admin@company.com',
      password: 'password'
    });
    const token = login.data.data.access_token;
    
    const res = await axios.get('http://localhost:80/api/v1/roles', {
      headers: { Authorization: `Bearer ${token}` }
    });
    console.log('Roles status:', res.status);
    console.log('Roles data:', JSON.stringify(res.data).substring(0, 100));
  } catch (err) {
    console.error('Error:', err.response ? err.response.status : err.message);
  }
}
test();

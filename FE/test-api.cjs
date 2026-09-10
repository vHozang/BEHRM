const axios = require('axios');

async function test() {
  try {
    const login = await axios.post('http://localhost:80/api/v1/auth/login', {
      company_email: 'admin@company.com',
      password: 'password'
    });
    const token = login.data.data.access_token;
    console.log('Token:', token ? 'OK' : 'FAIL');
    
    const depts = await axios.get('http://localhost:80/api/v1/departments', {
      headers: { Authorization: `Bearer ${token}` }
    });
    console.log('Departments data type:', typeof depts.data);
    console.log('Departments data.data type:', typeof depts.data.data);
    console.log('Is payload.items array?', Array.isArray(depts.data.data.items));
    console.log('Raw data string:', JSON.stringify(depts.data).substring(0, 300));
  } catch (err) {
    console.error(err.response ? err.response.data : err.message);
  }
}
test();

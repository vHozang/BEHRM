import assert from 'node:assert/strict';
import { asJobTitleArray, mapJobTitle } from './jobTitlePayload.js';

assert.deepEqual(mapJobTitle({
  id: 5,
  position_code: 'DEV',
  position_name: 'Lap trinh vien',
}), {
  id: 5,
  position_code: 'DEV',
  position_name: 'Lap trinh vien',
  name: 'Lap trinh vien',
  code: 'DEV',
  job_title_name: 'Lap trinh vien',
  title: 'Lap trinh vien',
});

assert.deepEqual(asJobTitleArray({
  items: [
    { id: 1, position_name: 'Nhan vien nhan su' },
    { id: 2, name: 'Ke toan' },
  ],
}).map((item) => item.name), ['Nhan vien nhan su', 'Ke toan']);

console.log('jobTitlePayload position mapping test passed');

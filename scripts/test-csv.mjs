import assert from 'node:assert/strict';
import { toCsv } from '../client/src/utils/csv.js';

const csv = toCsv([['name', 'amount'], ['Nguyễn, An', 10], ['=2+2', 20]]);
assert.equal(csv, '\uFEFF"name","amount"\n"Nguyễn, An","10"\n"\'=2+2","20"');

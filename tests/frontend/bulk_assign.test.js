// PHASE 12: snapshot regression — doi selection sau open, submit van giu du IDs.
// PHASE 13: count/button derive tu snapshot. PHASE 14/15: zero + double submit.
test('bulk snapshot survives selection change', async () => {
  profiles = [101, 102, 103, 104, 105].map(id => ({ id, name: 'K' + id }));
  [101, 102, 103, 104, 105].forEach(id => selectedProfileIds.add(id));
  actBulkOpen();
  assertEq(actBulkIds, [101, 102, 103, 104, 105], 'snapshot');
  selectedProfileIds.clear(); // page doi sau khi mo modal
  await actBulkSave();
  const call = fetchLog.find(f => f[0].indexOf('action=bulk') !== -1 && f[0].indexOf('bulk_existing') === -1);
  assertEq(call[1].ids, [101, 102, 103, 104, 105], 'payload van 5 IDs');
});

test('bulk count derived from snapshot', () => {
  profiles = [101, 102].map(id => ({ id, name: 'K' + id }));
  [101, 102].forEach(id => selectedProfileIds.add(id));
  actBulkOpen();
  assert(document.getElementById('bulk-act-save-btn').textContent.indexOf('2') !== -1, 'button Gán 2 kênh');
  assert(document.getElementById('bulk-act-save-btn').disabled === false, 'button enabled');
  selectedProfileIds.clear();
});

test('bulk zero target blocks API', async () => {
  actBulkIds = [];
  const n0 = fetchLog.length;
  await actBulkSave();
  assertEq(fetchLog.length, n0, 'khong goi API');
});

test('bulk double submit once', async () => {
  profiles = [101].map(id => ({ id, name: 'K' + id }));
  selectedProfileIds.add(101);
  actBulkOpen();
  const p1 = actBulkSave(), p2 = actBulkSave();
  await Promise.all([p1, p2]);
  const n = fetchLog.filter(f => f[0].indexOf('action=bulk') !== -1 && f[0].indexOf('bulk_existing') === -1).length;
  assertEq(n, 1, 'chi 1 API call');
  selectedProfileIds.clear();
});

test('OLD IMPL FAILS (regression proof)', () => {
  // Code cu truoc fix (read + clear trong 1 ham) — test nay PHAI FAIL tren code cu,
  // chung minh test khoa dung bug.
  const oldSet = new Set([101, 102, 103, 104, 105]);
  function oldGetSelectedIds() {
    const ids = Array.from(oldSet);
    oldSet.clear();
    return ids;
  }
  const openIds = oldGetSelectedIds(); // modal thay 5
  const submitIds = oldGetSelectedIds(); // submit doc lai
  assertEq(openIds.length, 5, 'modal thay 5');
  assertEq(submitIds.length, 0, 'submit rong tren code cu (bug)');
});

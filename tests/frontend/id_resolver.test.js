// PHASE 11: resolveProfileId — 1 helper duy nhat cho moi dang input.
test('resolve number/string/object', () => {
  assertEq(resolveProfileId(7), 7);
  assertEq(resolveProfileId(' 101 '), 101);
  assertEq(resolveProfileId({ id: 7, profile_id: 101 }), 101);
  assertEq(resolveProfileId({ id: 55 }), 55);
  assertEq(resolveProfileId({ profileId: 66 }), 66);
});
test('resolve invalid -> 0', () => {
  assertEq(resolveProfileId(null), 0);
  assertEq(resolveProfileId(undefined), 0);
  assertEq(resolveProfileId(0), 0);
  assertEq(resolveProfileId(-3), 0);
  assertEq(resolveProfileId('abc'), 0);
  assertEq(resolveProfileId({}), 0);
  assertEq(resolveProfileId({ name: 'x' }), 0);
});

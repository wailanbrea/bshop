import assert from 'node:assert/strict';
import test from 'node:test';
import { weeklyPeriodStart } from '../../resources/js/lib/weeklyPeriod.ts';

test('suggests the seven-day period ending on the configured ISO weekday', () => {
    const sunday = new Date('2026-11-15T12:00:00Z');
    assert.equal(weeklyPeriodStart('UTC', 7, sunday), '2026-11-09');
    assert.equal(weeklyPeriodStart('UTC', 6, sunday), '2026-11-15');
    assert.equal(weeklyPeriodStart('UTC', 1, sunday), '2026-11-10');
});

test('uses the business local calendar across midnight and DST changes', () => {
    assert.equal(weeklyPeriodStart('America/Santo_Domingo', 6, new Date('2026-11-15T01:00:00Z')), '2026-11-08');
    assert.equal(weeklyPeriodStart('America/New_York', 7, new Date('2026-03-08T06:59:00Z')), '2026-03-02');
    assert.equal(weeklyPeriodStart('America/New_York', 7, new Date('2026-03-08T07:01:00Z')), '2026-03-02');
    assert.equal(weeklyPeriodStart('UTC', null, new Date('2026-11-15T12:00:00Z')), '2026-11-15');
});

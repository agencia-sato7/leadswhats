import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createSingleFlight, settleRefreshes, startPolling } from './refresh';

function deferred() {
  let resolve!: () => void;
  const promise = new Promise<void>((done) => { resolve = done; });
  return { promise, resolve };
}

describe('polling', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('waits for slow requests and then waits five seconds before repeating', async () => {
    const request = deferred();
    const refresh = vi.fn(() => request.promise);
    const stop = startPolling(refresh, vi.fn());
    await vi.advanceTimersByTimeAsync(5000);
    expect(refresh).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(20000);
    expect(refresh).toHaveBeenCalledTimes(1);
    request.resolve();
    await vi.advanceTimersByTimeAsync(4999);
    expect(refresh).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(refresh).toHaveBeenCalledTimes(2);
    stop();
  });

  it('keeps the cycle pending after partial failure until all siblings finish', async () => {
    const request = deferred();
    const error = new Error('API unavailable');
    const onError = vi.fn();
    const refresh = vi.fn(async () => {
      await settleRefreshes([Promise.reject(error), request.promise]);
    });
    const stop = startPolling(refresh, onError);
    await vi.advanceTimersByTimeAsync(20000);
    expect(refresh).toHaveBeenCalledTimes(1);
    expect(onError).not.toHaveBeenCalled();
    request.resolve();
    await vi.advanceTimersByTimeAsync(0);
    expect(onError).toHaveBeenCalledWith(error);
    await vi.advanceTimersByTimeAsync(5000);
    expect(refresh).toHaveBeenCalledTimes(2);
    stop();
  });

  it('does not restart after cleanup while a request is pending', async () => {
    const request = deferred();
    const refresh = vi.fn(() => request.promise);
    const stop = startPolling(refresh, vi.fn());
    await vi.advanceTimersByTimeAsync(5000);
    stop();
    request.resolve();
    await vi.advanceTimersByTimeAsync(20000);
    expect(refresh).toHaveBeenCalledTimes(1);
  });

  it('cancels the initial timer on cleanup', async () => {
    const refresh = vi.fn(async () => {});
    startPolling(refresh, vi.fn())();
    await vi.advanceTimersByTimeAsync(10000);
    expect(refresh).not.toHaveBeenCalled();
  });

  it('shares pending work without mixing tenant/session keys and allows refresh after completion', async () => {
    const run = createSingleFlight();
    const request = deferred();
    const task = vi.fn(() => request.promise);
    const first = run('session-a/tenant-a', task);
    expect(run('session-a/tenant-a', task)).toBe(first);
    const other = run('session-a/tenant-b', task);
    expect(other).not.toBe(first);
    await Promise.resolve();
    expect(task).toHaveBeenCalledTimes(2);
    request.resolve();
    await Promise.all([first, other]);
    await run('session-a/tenant-a', task);
    expect(task).toHaveBeenCalledTimes(3);
  });

  it('releases pending work after failure', async () => {
    const run = createSingleFlight();
    await expect(run('tenant', async () => { throw new Error('offline'); })).rejects.toThrow('offline');
    const retry = vi.fn(async () => {});
    await run('tenant', retry);
    expect(retry).toHaveBeenCalledTimes(1);
  });

  it('preserves tuple order and values', async () => {
    await expect(settleRefreshes([Promise.resolve(1), Promise.resolve('ok')] as const))
      .resolves.toEqual([1, 'ok']);
  });
});

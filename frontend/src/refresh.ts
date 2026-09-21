// Share pending refreshes only within the same session/tenant and data epoch.
export function createSingleFlight() {
  const pending = new Map<string, Promise<void>>();
  return (key: string, task: () => Promise<void>): Promise<void> => {
    const existing = pending.get(key);
    if (existing) return existing;
    const result = Promise.resolve().then(task).finally(() => pending.delete(key));
    pending.set(key, result);
    return result;
  };
}


// Do not release a polling cycle while sibling requests remain pending.
export async function settleRefreshes<T extends readonly unknown[]>(
  tasks: { [K in keyof T]: Promise<T[K]> },
): Promise<T> {
  const results = await Promise.allSettled(tasks);
  return results.map((result) => {
    if (result.status === 'rejected') throw result.reason;
    return result.value;
  }) as unknown as T;
}

export function startPolling(
  refresh: () => Promise<void>,
  onError: (error: unknown) => void,
  delay = 5000,
): () => void {
  let stopped = false;
  let timer: ReturnType<typeof setTimeout>;
  const tick = async () => {
    try {
      await refresh();
    } catch (error) {
      if (!stopped) onError(error);
    } finally {
      if (!stopped) timer = setTimeout(tick, delay);
    }
  };
  timer = setTimeout(tick, delay);
  return () => {
    stopped = true;
    clearTimeout(timer);
  };
}

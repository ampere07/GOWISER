import { create } from 'zustand';
import { getJobOrders, ApiResponse } from '../services/jobOrderService';
import { JobOrder } from '../types/jobOrder';

type Pagination = ApiResponse<unknown>['pagination'];

interface JobOrderState {
    jobOrders: JobOrder[];
    totalCount: number;
    isLoading: boolean;
    error: string | null;
    hasMore: boolean;
    currentPage: number;
    lastUpdated: Date | null;
    isFullyLoaded: boolean;
    // Server-clock time the next poll asks for changes since. Null until a full load has landed.
    syncCursor: string | null;

    fetchJobOrders: (assignedEmail?: string, silent?: boolean) => Promise<void>;
    refreshJobOrders: (assignedEmail?: string) => Promise<void>;
    silentRefresh: (assignedEmail?: string) => Promise<void>;
    fetchUpdates: (assignedEmail?: string) => Promise<void>;
    clearJobOrders: () => void;
}

const CHUNK_SIZE = 1000;

// How far before the server's clock each poll reaches back. A row saved inside a slow transaction
// carries the updated_at it was written with, not the moment it committed, so a cursor at exactly
// "now" can step past it. Re-reading a few minutes of rows is cheap, and merging one twice is harmless.
const CURSOR_OVERLAP_MS = 5 * 60 * 1000;

// Turns the server time a response reports into the next poll's cursor.
//
// The cursor has to come from the server: updated_at is the server's wall clock, and a cursor built
// from the browser's clock misses changes whenever that clock or its timezone is off — which left
// each browser holding a slightly different copy of the job orders, so the same funnel filter gave
// different counts on different machines. The string is shifted as if it were UTC only so that no
// local offset is applied on the way in or out.
const cursorFrom = (serverTime?: string | null): string => {
    const parsed = serverTime ? Date.parse(`${serverTime.replace(' ', 'T')}Z`) : NaN;
    // A backend too old to report server_time: fall back to the browser clock, as before.
    const base = isNaN(parsed) ? Date.now() : parsed;
    return new Date(base - CURSOR_OVERLAP_MS).toISOString().slice(0, 19).replace('T', ' ');
};

const byNewest = (a: JobOrder, b: JobOrder) => {
    const timeA = new Date(a.Timestamp || a.timestamp || a.created_at || 0).getTime();
    const timeB = new Date(b.Timestamp || b.timestamp || b.created_at || 0).getTime();
    if (timeA !== timeB) return timeB - timeA;

    // Fallback to ID sorting if timestamps are same
    const idA = parseInt(String(a.id)) || 0;
    const idB = parseInt(String(b.id)) || 0;
    return idB - idA;
};

// The full load in flight. Mount, Pusher events, the idle timer and the refresh button can all ask
// for one at once; each used to run its own, and whichever finished last overwrote the others with
// its older snapshot — after the poll had already moved past those changes, so they never came back.
// Callers now share the one load.
let activeLoad: Promise<void> | null = null;
let activeLoadEmail: string | undefined;
// Bumped when a load starts, so a poll that was in flight across it discards its result.
let loadGeneration = 0;
let pollInFlight = false;

export const useJobOrderStore = create<JobOrderState>((set, get) => {
    // Reads every page from the server and, once all have arrived, replaces the list with them.
    const loadAll = async (assignedEmail: string | undefined, silent: boolean) => {
        // A silent refresh over a list already on screen keeps showing it until the new one is
        // complete; otherwise rows fill in page by page.
        const progressive = !silent || get().jobOrders.length === 0;
        if (progressive) set({ isLoading: true, error: null });

        const fetched = new Map<JobOrder['id'], JobOrder>();
        let cursor: string | null = null;
        let total = 0;
        let complete = false;

        try {
            for (let page = 1; ; page++) {
                const result = await getJobOrders(false, page, CHUNK_SIZE, '', assignedEmail);
                if (!result || !result.success || !Array.isArray(result.jobOrders)) {
                    if (page === 1) set({ error: result?.message || 'Failed to fetch job orders' });
                    break;
                }

                const pagination: Pagination = result.pagination;

                // Read before any page, so everything changed while the rest load is newer than it
                // and the first poll afterwards picks it up.
                if (page === 1) cursor = cursorFrom(pagination?.server_time);

                result.jobOrders.forEach(jo => fetched.set(jo.id, jo));
                total = pagination?.total_count || (pagination as any)?.total || Math.max(total, fetched.size);
                const more = result.jobOrders.length > 0 && (pagination?.has_more ?? fetched.size < total);

                if (progressive) {
                    set({
                        jobOrders: Array.from(fetched.values()).sort(byNewest),
                        totalCount: total,
                        isLoading: false,
                        hasMore: more,
                        isFullyLoaded: !more,
                        currentPage: page,
                        lastUpdated: new Date(),
                        error: null
                    });
                }

                if (!more) {
                    complete = true;
                    break;
                }
            }
        } catch (err: any) {
            console.error('[JobOrderStore] Fetch failed:', err);
            if (fetched.size === 0) set({ error: err.message || 'Failed to fetch job orders' });
        }

        if (complete) {
            // A complete read of the server, so it replaces the list outright: rows deleted or moved
            // out of the organisation since the last load drop out instead of lingering here.
            set({
                jobOrders: Array.from(fetched.values()).sort(byNewest),
                totalCount: total,
                hasMore: false,
                isFullyLoaded: true,
                lastUpdated: new Date(),
                syncCursor: cursor,
                error: null
            });
        } else if (fetched.size > 0) {
            // A later page failed. Lay what did arrive over the current list and leave the cursor
            // alone, so the poll still covers everything since the last complete load. The loading
            // indicator ends either way; the next refresh retries the missing pages.
            set(state => {
                const merged = new Map(state.jobOrders.map(jo => [jo.id, jo]));
                fetched.forEach((jo, id) => merged.set(id, jo));
                return {
                    jobOrders: Array.from(merged.values()).sort(byNewest),
                    hasMore: false,
                    isFullyLoaded: true,
                    lastUpdated: new Date(),
                    syncCursor: state.syncCursor ?? cursor
                };
            });
        }

        set({ isLoading: false });
    };

    const startLoad = (assignedEmail: string | undefined, silent: boolean): Promise<void> => {
        if (activeLoad) {
            if (activeLoadEmail === assignedEmail) return activeLoad;
            // A different scope was asked for — a technician's email resolving after mount — so run
            // it after the current load rather than alongside it, and let its result be the one kept.
            return activeLoad.then(() => startLoad(assignedEmail, silent));
        }

        loadGeneration++;
        activeLoadEmail = assignedEmail;
        activeLoad = loadAll(assignedEmail, silent).finally(() => {
            activeLoad = null;
        });
        return activeLoad;
    };

    return {
        jobOrders: [],
        totalCount: 0,
        isLoading: false,
        error: null,
        hasMore: true,
        currentPage: 1,
        lastUpdated: null,
        isFullyLoaded: false,
        syncCursor: null,

        fetchJobOrders: (assignedEmail?: string, silent = false) => startLoad(assignedEmail, silent),

        refreshJobOrders: (assignedEmail?: string) => startLoad(assignedEmail, false),

        silentRefresh: (assignedEmail?: string) => startLoad(assignedEmail, true),

        clearJobOrders: () => {
            set({
                jobOrders: [],
                totalCount: 0,
                isLoading: false,
                error: null,
                hasMore: true,
                currentPage: 1,
                lastUpdated: null,
                isFullyLoaded: false,
                syncCursor: null,
            });
        },

        fetchUpdates: async (assignedEmail?: string) => {
            // A full load under way is already catching up, and its cursor covers whatever it misses.
            if (activeLoad || pollInFlight) return;

            const since = get().syncCursor;
            if (!since) {
                await get().silentRefresh(assignedEmail);
                return;
            }

            const generation = loadGeneration;
            pollInFlight = true;
            try {
                const response = await getJobOrders(false, 1, CHUNK_SIZE, '', assignedEmail, since);
                // Nothing is applied and the cursor stays put, so the next tick asks again from the same point.
                if (!response.success || !Array.isArray(response.jobOrders)) return;
                // A full load started meanwhile and supersedes this.
                if (generation !== loadGeneration) return;

                if (response.pagination?.has_more) {
                    // More changed than one page holds — after the machine slept, say. Only the first
                    // page used to be merged while the cursor moved past the rest, losing them for good.
                    await get().silentRefresh(assignedEmail);
                    return;
                }

                const changed = response.jobOrders;
                const nextCursor = cursorFrom((response.pagination as Pagination)?.server_time);

                set((state) => {
                    if (changed.length === 0) {
                        return { syncCursor: nextCursor, lastUpdated: new Date() };
                    }

                    const currentMap = new Map(state.jobOrders.map(jo => [jo.id, jo]));
                    let added = 0;
                    changed.forEach(jo => {
                        const existing = currentMap.get(jo.id);
                        if (!existing) added++;
                        currentMap.set(jo.id, existing ? { ...existing, ...jo } : jo);
                    });

                    return {
                        jobOrders: Array.from(currentMap.values()).sort(byNewest),
                        // The poll's own total_count is the number of changed rows, not the list size.
                        totalCount: state.totalCount + added,
                        syncCursor: nextCursor,
                        lastUpdated: new Date()
                    };
                });
            } catch (err) {
                console.error('[JobOrderStore] Polling failed:', err);
            } finally {
                pollInFlight = false;
            }
        }
    };
});

-- Run this after the large dataset has been loaded into borrow_items.
-- The baseline disables index scans to show the sequential-scan plan consistently,
-- even if the application has already created the indexes.

ANALYZE public.borrow_items;
ANALYZE public.equipment;
ANALYZE public.borrow_transactions;

BEGIN;
SET LOCAL enable_indexscan = off;
SET LOCAL enable_indexonlyscan = off;
SET LOCAL enable_bitmapscan = off;

EXPLAIN (ANALYZE, BUFFERS)
SELECT borrow_id, borrower_id, borrow_date, due_date, status
FROM public.borrow_transactions
ORDER BY borrow_date DESC, borrow_id DESC
LIMIT 50;

COMMIT;

-- Indexes for foreign-key joins, case-insensitive status filters, and recent lists.
-- borrow_items(borrow_id) is already covered by the leading column of its
-- existing UNIQUE (borrow_id, equipment_id) constraint.
CREATE INDEX IF NOT EXISTS borrow_items_equipment_id_idx
    ON public.borrow_items (equipment_id);
CREATE INDEX IF NOT EXISTS borrow_transactions_borrower_id_idx
    ON public.borrow_transactions (borrower_id);
CREATE INDEX IF NOT EXISTS borrow_transactions_status_lower_idx
    ON public.borrow_transactions (lower(status));
CREATE INDEX IF NOT EXISTS borrow_transactions_recent_idx
    ON public.borrow_transactions (borrow_date DESC, borrow_id DESC);

ANALYZE public.borrow_items;
ANALYZE public.borrow_transactions;

-- After: PostgreSQL can use the recent-transactions index for the same query.
EXPLAIN (ANALYZE, BUFFERS)
SELECT borrow_id, borrower_id, borrow_date, due_date, status
FROM public.borrow_transactions
ORDER BY borrow_date DESC, borrow_id DESC
LIMIT 50;

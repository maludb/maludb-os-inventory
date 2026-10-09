-- 019: WHAT SLICE 3 FOUND — an additive fix to db/016 (docs/build-specs/sources.md "Built and proven"). A migration is never modified; this one adds.
--
--  1. inv_source_pull_finish() (db/016's, which reads the robots verdict as a scalar or a map): an `ok` finish set `sources.last_ok_at` whatever
--     the pull's kind. A probe (a handful of requests) and a live search (a few listings) are pulls of their own kinds, and `last_ok_at` is the
--     clock inv_sources_due() and inv_source_health() read — so probing a new source made it "ok" on the health chip and kept the worker from its
--     FIRST real pull for a whole schedule (30 minutes, 6 hours, a day for a marked-up site). Now only a `scheduled` or `manual` pull moves
--     `last_ok_at`; a probe or a search that answers still clears the ladder and records the robots verdict. db/016's body, that line changed.
BEGIN;

CREATE OR REPLACE FUNCTION inv_source_pull_finish(p_pull_id bigint, p_status text, p_error text DEFAULT NULL, p_policy jsonb DEFAULT NULL,
                                                  p_http_requests integer DEFAULT NULL, p_bytes bigint DEFAULT NULL) RETURNS sources
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p source_pulls%ROWTYPE; s sources%ROWTYPE; ladder integer[]; n integer; verdict text := inv_robots_verdict(p_policy);
BEGIN
    IF p_status NOT IN ('ok', 'partial', 'failed', 'blocked') THEN RAISE EXCEPTION 'A pull finishes ok, partial, failed or blocked' USING ERRCODE = 'check_violation'; END IF;
    UPDATE source_pulls SET status = p_status, finished_at = now(), error = left(p_error, 200), policy = COALESCE(p_policy, policy),
                            http_requests = COALESCE(p_http_requests, http_requests), bytes = COALESCE(p_bytes, bytes)
     WHERE id = p_pull_id RETURNING * INTO p;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such pull' USING ERRCODE = 'no_data_found'; END IF;
    SELECT * INTO s FROM sources WHERE id = p.source_id FOR UPDATE;
    IF p_status IN ('ok', 'partial') THEN
        UPDATE sources SET last_ok_at = CASE WHEN p.kind IN ('scheduled', 'manual') THEN now() ELSE last_ok_at END,   -- a probe or a search is not a pull of the catalog
                           consecutive_failures = 0, backoff_until = NULL,
                           robots_state = COALESCE(verdict, robots_state),
                           robots_checked_at = CASE WHEN verdict IS NOT NULL THEN now() ELSE robots_checked_at END
         WHERE id = s.id RETURNING * INTO s;
    ELSE
        ladder := (SELECT crawl_backoff_minutes FROM inv_settings WHERE id = 1);
        n := s.consecutive_failures + 1;
        IF n <= cardinality(ladder) THEN
            UPDATE sources SET consecutive_failures = n, backoff_until = now() + make_interval(mins => ladder[n]),
                               robots_state = CASE WHEN p_status = 'blocked' THEN 'blocked' ELSE COALESCE(verdict, robots_state) END,
                               robots_checked_at = CASE WHEN p_status = 'blocked' OR verdict IS NOT NULL THEN now() ELSE robots_checked_at END
             WHERE id = s.id RETURNING * INTO s;
        ELSE
            UPDATE sources SET consecutive_failures = n, backoff_until = NULL, paused_at = now(),
                               paused_reason = format('%s failures in a row (last: %s)', n, COALESCE(left(p_error, 120), p_status)),
                               robots_state = CASE WHEN p_status = 'blocked' THEN 'blocked' ELSE COALESCE(verdict, robots_state) END
             WHERE id = s.id RETURNING * INTO s;
        END IF;
    END IF;
    RETURN s;
END$$;
REVOKE ALL ON FUNCTION inv_source_pull_finish(bigint, text, text, jsonb, integer, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inv_source_pull_finish(bigint, text, text, jsonb, integer, bigint) TO inventory_rw;

COMMIT;

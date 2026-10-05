-- 013: NOTIFICATIONS, the OUTBOX, AGENT DISPATCHES, the BUYER AGENT'S PROPOSALS, the availability FEED'S KEYS and their
-- counters, and the firing of watches (design §6 "Notifications", "Agents", "Feed"; §4; §5; D12).
--
-- THE ESTATE'S DATA MODEL (design §6.3): `notifications`, `notification_prefs`, `notification_outbox` and `agent_dispatches`
-- are GL db/014's canonical definitions with Spaces' substitution — EVERY RECIPIENT IS A MEMBER (a customer or a supplier
-- is reached by the order's or the PO's own email from the handler, never from this outbox), so the external-party column
-- and its pair CHECK are dropped; the `kind` lists are ours; `agent_dispatches.kind` widened to watch/ask/duty_proposal and
-- `watch_id`, `listing_variant_id` APPENDED. `key_usage` is Knowledge db/011's, verbatim, keyed to `feed_keys`. `feed_keys`
-- takes `mcp_access_tokens`'s shape (db/003) plus Knowledge's bound-token columns (label, the consumer, limits, rotation).
-- `buyer_proposals` is new. DECISION: a partner price list (§4) has no table in §6 — it is a NAMED PERCENTAGE OFF RETAIL
-- (`price_lists`: one small table, an admin screen) that a partner key names; the feed answer computes the partner price.
BEGIN;

CREATE TABLE notifications (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind         text NOT NULL CHECK (kind IN ('watch', 'line_at_risk', 'pull_failed', 'pull_blocked', 'po_ack', 'po_decline', 'po_tracking', 'return',
                                               'morning_note', 'mention', 'order', 'agent_drafted', 'unmatched')),
    record_type  text,
    record_id    bigint,
    title        text NOT NULL,
    body         text,
    read_at      timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notifications_member_idx ON notifications (member_id, created_at DESC) WHERE read_at IS NULL;
CREATE INDEX notifications_record_idx ON notifications (record_type, record_id);

CREATE TABLE notification_prefs (
    member_id      bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    email_enabled  boolean NOT NULL DEFAULT true,
    text_enabled   boolean NOT NULL DEFAULT false,                -- K6: the kernel keeps the opt-out; this is the person's choice here
    kinds          text[] NOT NULL DEFAULT '{watch,line_at_risk,pull_failed,pull_blocked,po_ack,po_decline,po_tracking,return,morning_note,mention}',
    text_kinds     text[] NOT NULL DEFAULT '{watch,line_at_risk}',
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER notification_prefs_touch BEFORE UPDATE ON notification_prefs FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- One row per recipient AND channel; a K6 refusal is a SKIP with the code and the email still goes.
CREATE TABLE notification_outbox (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel        text NOT NULL CHECK (channel IN ('email', 'text')),
    member_id      bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    to_email       citext,                                                 -- the member's address at send time
    kind           text NOT NULL,
    record_type    text,
    record_id      bigint,
    dedupe_key     text,
    subject        text,
    body           text NOT NULL,
    body_html      text,
    status         text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'skipped', 'failed')),
    attempts       integer NOT NULL DEFAULT 0,
    detail         text,
    provider_ref   text,                                                   -- MaluMail's message id, or the kernel's notification id
    send_after     timestamptz NOT NULL DEFAULT now(),
    sent_at        timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX notification_outbox_dedupe ON notification_outbox (dedupe_key) WHERE dedupe_key IS NOT NULL;
CREATE INDEX notification_outbox_queue_idx ON notification_outbox (status, send_after) WHERE status = 'queued';
CREATE INDEX notification_outbox_member_idx ON notification_outbox (member_id, created_at DESC);
CREATE INDEX notification_outbox_record_idx ON notification_outbox (record_type, record_id);

-- One notification to one member, queued to the channels their preferences allow (a dedupe key keeps it to one).
CREATE OR REPLACE FUNCTION inv_notify(p_member_id bigint, p_kind text, p_record_type text, p_record_id bigint, p_title text, p_body text DEFAULT NULL,
                                      p_dedupe text DEFAULT NULL, p_force_text boolean DEFAULT false) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE nid bigint; pf notification_prefs%ROWTYPE; em citext;
BEGIN
    IF p_dedupe IS NOT NULL AND EXISTS (SELECT 1 FROM notification_outbox WHERE dedupe_key = p_dedupe || ':email') THEN RETURN NULL; END IF;
    INSERT INTO notifications (member_id, kind, record_type, record_id, title, body) VALUES (p_member_id, p_kind, p_record_type, p_record_id, p_title, p_body) RETURNING id INTO nid;
    SELECT * INTO pf FROM notification_prefs WHERE member_id = p_member_id;
    SELECT email INTO em FROM members WHERE id = p_member_id;
    IF (NOT FOUND OR pf.member_id IS NULL) THEN
        INSERT INTO notification_prefs (member_id) VALUES (p_member_id) ON CONFLICT DO NOTHING;
        SELECT * INTO pf FROM notification_prefs WHERE member_id = p_member_id;
    END IF;
    IF pf.email_enabled AND p_kind = ANY (pf.kinds) AND em IS NOT NULL THEN
        INSERT INTO notification_outbox (channel, member_id, to_email, kind, record_type, record_id, dedupe_key, subject, body)
        VALUES ('email', p_member_id, em, p_kind, p_record_type, p_record_id, CASE WHEN p_dedupe IS NULL THEN NULL ELSE p_dedupe || ':email' END, p_title, COALESCE(p_body, p_title))
        ON CONFLICT DO NOTHING;
    END IF;
    IF (p_force_text OR (pf.text_enabled AND p_kind = ANY (pf.text_kinds))) THEN
        INSERT INTO notification_outbox (channel, member_id, kind, record_type, record_id, dedupe_key, body)
        VALUES ('text', p_member_id, p_kind, p_record_type, p_record_id, CASE WHEN p_dedupe IS NULL THEN NULL ELSE p_dedupe || ':text' END, left(COALESCE(p_body, p_title), 300))
        ON CONFLICT DO NOTHING;
    END IF;
    RETURN nid;
END$$;

-- ---------------------------------------------------------------------------------------------
-- What was handed to an agent (design §5): a watch naming an agent, a question, a duty's proposal.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE agent_dispatches (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type      text,
    record_id        bigint,
    agent_member_id  bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind             text NOT NULL CHECK (kind IN ('watch', 'ask', 'duty_proposal')),
    via              text NOT NULL CHECK (via IN ('chat', 'wake', 'duty')),
    acting_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    run_id           bigint,
    request_id       text,
    status           text NOT NULL DEFAULT 'sent' CHECK (status IN ('sent', 'answered', 'refused', 'failed', 'awaiting_approval')),
    reply_excerpt    text,                                                 -- at most 200 characters
    detail           text,
    attempts         integer NOT NULL DEFAULT 1,
    created_at       timestamptz NOT NULL DEFAULT now(),
    answered_at      timestamptz,
    -- appended (design §6.3)
    watch_id             bigint REFERENCES watches(id) ON DELETE SET NULL,
    listing_variant_id   bigint REFERENCES listing_variants(id) ON DELETE SET NULL
);
CREATE INDEX agent_dispatches_record_idx ON agent_dispatches (record_type, record_id, created_at DESC);
CREATE INDEX agent_dispatches_agent_idx ON agent_dispatches (agent_member_id, created_at DESC);
CREATE INDEX agent_dispatches_acting_idx ON agent_dispatches (acting_member_id);
CREATE INDEX agent_dispatches_watch_idx ON agent_dispatches (watch_id);
CREATE INDEX agent_dispatches_pending_idx ON agent_dispatches (status, created_at) WHERE status = 'sent';

-- The Buyer agent's morning note, as rows (§5): what it found and what it drafted; a person accepts or dismisses.
CREATE TABLE buyer_proposals (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind                 text NOT NULL CHECK (kind IN ('reorder', 'match', 'price', 'at_risk', 'source', 'po_overdue', 'return')),
    subject_type         text NOT NULL,                                     -- 'product_variant', 'listing_variant', 'sales_order_line', 'source', 'purchase_order', 'return'
    subject_id           bigint NOT NULL,
    title                text NOT NULL,
    detail               jsonb NOT NULL DEFAULT '{}'::jsonb,
    drafted_record_type  text,                                              -- 'purchase_order', 'match_proposal'
    drafted_record_id    bigint,
    status               text NOT NULL DEFAULT 'proposed' CHECK (status IN ('proposed', 'accepted', 'dismissed')),
    proposed_by          bigint REFERENCES members(id) ON DELETE SET NULL,  -- the agent
    note_date            date NOT NULL DEFAULT current_date,
    decided_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at           timestamptz,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX buyer_proposals_date_idx ON buyer_proposals (note_date DESC, kind);
CREATE INDEX buyer_proposals_subject_idx ON buyer_proposals (subject_type, subject_id);
CREATE INDEX buyer_proposals_open_idx ON buyer_proposals (status) WHERE status = 'proposed';
CREATE TRIGGER buyer_proposals_touch BEFORE UPDATE ON buyer_proposals FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ---------------------------------------------------------------------------------------------
-- Watches fire (§6: once per state change, not per pull): the member is notified (and texted when they asked — K6), the
-- named agent is dispatched. Returns how many fired. The worker calls it after every pull and the heartbeat.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_watch_title(p_watch_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE w.kind WHEN 'back_in_stock' THEN 'Back in stock: ' WHEN 'price_below' THEN 'Price below ' || w.threshold || ': ' WHEN 'cost_below' THEN 'Cost below ' || w.threshold || ': '
                       WHEN 'map_breach' THEN 'MAP breached: ' WHEN 'lead_time_over' THEN 'Lead time over ' || w.threshold || ' days: ' ELSE 'Removed: ' END
           || COALESCE((SELECT p.name || ' ' || COALESCE(v.option_values->>'Size', v.sku) FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = w.variant_id),
                       (SELECT l.title || ' — ' || COALESCE(lv.title, lv.sku, lv.external_variant_id) FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id WHERE lv.id = w.listing_variant_id),
                       (SELECT name FROM products WHERE id = w.product_id), 'watch ' || w.id)
      FROM watches w WHERE w.id = p_watch_id;
$$;

CREATE OR REPLACE FUNCTION inv_fire_watches() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE w record; st boolean; n integer := 0; title text;
BEGIN
    FOR w IN SELECT * FROM watches WHERE active ORDER BY id LOOP
        st := inv_watch_state(w.id);
        IF w.last_state IS NULL THEN
            UPDATE watches SET last_state = st WHERE id = w.id;           -- first evaluation: a state already true does not fire (nothing changed)
        ELSIF st AND NOT w.last_state THEN
            title := inv_watch_title(w.id);
            UPDATE watches SET last_state = true, fired_at = now(), fire_count = fire_count + 1 WHERE id = w.id;
            PERFORM inv_notify(w.member_id, 'watch', 'watch', w.id, title, NULL, 'watch:' || w.id || ':' || (w.fire_count + 1), w.text_me);
            IF w.agent_member_id IS NOT NULL THEN
                INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, watch_id, listing_variant_id, detail)
                VALUES ('watch', w.id, w.agent_member_id, 'watch', 'chat', w.member_id, w.id, w.listing_variant_id, title);
            END IF;
            n := n + 1;
        ELSIF NOT st AND w.last_state THEN
            UPDATE watches SET last_state = false WHERE id = w.id;        -- cleared: it may fire again on the next change
        END IF;
    END LOOP;
    RETURN n;
END$$;

-- ---------------------------------------------------------------------------------------------
-- The feed's keys (§4): a key per consumer, hashed, labelled, limited, rotated with an overlap, revoked.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE price_lists (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name                text NOT NULL,
    percent_off_retail  numeric(5,2) NOT NULL DEFAULT 0 CHECK (percent_off_retail >= 0 AND percent_off_retail < 100),
    notes               text,
    active              boolean NOT NULL DEFAULT true,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX price_lists_name_idx ON price_lists (lower(name));
CREATE TRIGGER price_lists_touch BEFORE UPDATE ON price_lists FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE feed_keys (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id        bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,   -- the minter (an admin)
    label            text NOT NULL,
    token_hash       text NOT NULL UNIQUE,                                        -- sha256(raw); the raw form is `feed_` + 48 hex, shown once
    scope            text NOT NULL DEFAULT 'feed' CHECK (scope = 'feed'),
    consumer_kind    text NOT NULL DEFAULT 'website' CHECK (consumer_kind IN ('website', 'installation', 'partner')),
    price_list_id    bigint REFERENCES price_lists(id) ON DELETE SET NULL,       -- partner keys: the partner price
    rate_per_minute  integer CHECK (rate_per_minute IS NULL OR rate_per_minute BETWEEN 1 AND 100000),   -- NULL at insert = the setting
    rate_per_day     integer CHECK (rate_per_day IS NULL OR rate_per_day BETWEEN 1 AND 10000000),
    rotated_from     bigint REFERENCES feed_keys(id) ON DELETE SET NULL,
    last_used_at     timestamptz,
    expires_at       timestamptz,
    revoked_at       timestamptz,
    revoked_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CHECK (consumer_kind = 'partner' OR price_list_id IS NULL)
);
CREATE INDEX feed_keys_member_idx ON feed_keys (member_id);
CREATE INDEX feed_keys_live_idx ON feed_keys (id) WHERE revoked_at IS NULL;
CREATE TRIGGER feed_keys_touch BEFORE UPDATE ON feed_keys FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION inv_feed_keys_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    NEW.rate_per_minute := COALESCE(NEW.rate_per_minute, inv_setting_int('feed_rate_per_minute'));
    NEW.rate_per_day := COALESCE(NEW.rate_per_day, inv_setting_int('feed_rate_per_day'));
    RETURN NEW;
END$$;
CREATE TRIGGER feed_keys_before BEFORE INSERT OR UPDATE ON feed_keys FOR EACH ROW EXECUTE FUNCTION inv_feed_keys_before();

CREATE TABLE key_usage (
    token_id      bigint NOT NULL REFERENCES feed_keys(id) ON DELETE CASCADE,
    bucket_kind   text NOT NULL CHECK (bucket_kind IN ('minute', 'day')),
    bucket_start  timestamptz NOT NULL,
    calls         integer NOT NULL DEFAULT 0 CHECK (calls >= 0),
    refused       integer NOT NULL DEFAULT 0 CHECK (refused >= 0),
    PRIMARY KEY (token_id, bucket_kind, bucket_start)
);
CREATE INDEX key_usage_time_idx ON key_usage (bucket_kind, bucket_start);

-- Resolve a key by its hash: live, not revoked, not expired, its minter still admitted (a revoked grant shuts this door too).
CREATE OR REPLACE FUNCTION inv_resolve_feed_key(p_hash text)
    RETURNS TABLE (key_id bigint, member_id bigint, label text, consumer_kind text, price_list_id bigint, rate_per_minute integer, rate_per_day integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE feed_keys k SET last_used_at = now() FROM members m
     WHERE k.token_hash = p_hash AND k.revoked_at IS NULL AND (k.expires_at IS NULL OR k.expires_at > now()) AND m.id = k.member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN QUERY
        SELECT k.id, k.member_id, k.label, k.consumer_kind, k.price_list_id, k.rate_per_minute, k.rate_per_day
          FROM feed_keys k JOIN members m ON m.id = k.member_id
         WHERE k.token_hash = p_hash AND k.revoked_at IS NULL AND (k.expires_at IS NULL OR k.expires_at > now()) AND m.status = 'active' AND m.capability IS NOT NULL;
END$$;

-- Count one call (Knowledge's rule, verbatim): the minute bucket first; a call the minute refuses is not charged to the day.
CREATE OR REPLACE FUNCTION inv_rate_ok(p_key_id bigint, p_at timestamptz DEFAULT now())
    RETURNS TABLE (ok boolean, limit_hit text, retry_after integer, minute_calls integer, day_calls integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t feed_keys%ROWTYPE; m_start timestamptz; d_start timestamptz; m_calls integer; d_calls integer;
BEGIN
    SELECT * INTO t FROM feed_keys WHERE id = p_key_id FOR UPDATE;
    IF NOT FOUND THEN RETURN QUERY SELECT false, 'no_key'::text, 0, 0, 0; RETURN; END IF;
    IF t.revoked_at IS NOT NULL OR (t.expires_at IS NOT NULL AND t.expires_at <= p_at) THEN RETURN QUERY SELECT false, 'revoked'::text, 0, 0, 0; RETURN; END IF;
    m_start := date_trunc('minute', p_at);
    d_start := date_trunc('day', p_at);
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_key_id, 'minute', m_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1 RETURNING calls INTO m_calls;
    IF m_calls > t.rate_per_minute THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_key_id AND bucket_kind = 'minute' AND bucket_start = m_start;
        SELECT COALESCE(calls, 0) INTO d_calls FROM key_usage WHERE token_id = p_key_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'minute'::text, GREATEST(1, ceil(extract(epoch FROM (m_start + interval '1 minute' - p_at)))::integer), m_calls - 1, COALESCE(d_calls, 0);
        RETURN;
    END IF;
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_key_id, 'day', d_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1 RETURNING calls INTO d_calls;
    IF d_calls > t.rate_per_day THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_key_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'day'::text, GREATEST(1, ceil(extract(epoch FROM (d_start + interval '1 day' - p_at)))::integer), m_calls, d_calls - 1;
        RETURN;
    END IF;
    RETURN QUERY SELECT true, NULL::text, 0, m_calls, d_calls;
END$$;

CREATE OR REPLACE FUNCTION inv_feed_key_calls_today(p_key_id bigint) RETURNS integer
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((SELECT calls FROM key_usage WHERE token_id = p_key_id AND bucket_kind = 'day' AND bucket_start = date_trunc('day', now())), 0);
$$;

-- Rotate: a new key under the same minter, label, consumer and limits; the old one expires after the overlap (24 h).
-- PHP mints the raw 48 hex, hashes them and passes the hash in.
CREATE OR REPLACE FUNCTION inv_feed_key_rotate(p_old_key_id bigint, p_new_hash text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o feed_keys%ROWTYPE; nid bigint; overlap integer;
BEGIN
    SELECT * INTO o FROM feed_keys WHERE id = p_old_key_id FOR UPDATE;
    IF NOT FOUND OR o.revoked_at IS NOT NULL THEN RAISE EXCEPTION 'Only a live key is rotated' USING ERRCODE = 'check_violation'; END IF;
    overlap := inv_setting_int('key_rotation_overlap_hours');
    INSERT INTO feed_keys (member_id, label, token_hash, consumer_kind, price_list_id, rate_per_minute, rate_per_day, rotated_from)
    VALUES (o.member_id, o.label, p_new_hash, o.consumer_kind, o.price_list_id, o.rate_per_minute, o.rate_per_day, o.id) RETURNING id INTO nid;
    UPDATE feed_keys SET expires_at = LEAST(COALESCE(expires_at, 'infinity'::timestamptz), now() + make_interval(hours => overlap)) WHERE id = o.id;
    RETURN nid;
END$$;

CREATE OR REPLACE FUNCTION inv_feed_key_revoke(p_key_id bigint, p_by bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE feed_keys SET revoked_at = now(), revoked_by = p_by WHERE id = p_key_id AND revoked_at IS NULL;
END$$;

-- The worker prunes buckets: minutes after a day, days after 35.
CREATE OR REPLACE FUNCTION inv_prune_key_usage() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    DELETE FROM key_usage WHERE (bucket_kind = 'minute' AND bucket_start < now() - interval '1 day') OR (bucket_kind = 'day' AND bucket_start < now() - interval '35 days');
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

-- The worker's other passes: expire the doors' links once their time has come (a no-op until the order or PO closes).
CREATE OR REPLACE FUNCTION inv_expire_links() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer := 0; k integer;
BEGIN
    UPDATE order_links_secure SET rotated_at = now() WHERE rotated_at IS NULL AND expires_at IS NOT NULL AND expires_at <= now();
    GET DIAGNOSTICS k = ROW_COUNT; n := n + k;
    UPDATE supplier_links_secure SET rotated_at = now() WHERE rotated_at IS NULL AND expires_at IS NOT NULL AND expires_at <= now();
    GET DIAGNOSTICS k = ROW_COUNT; n := n + k;
    RETURN n;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON notifications, notification_prefs, notification_outbox, agent_dispatches, buyer_proposals, price_lists, feed_keys, key_usage TO inventory_rw;
GRANT EXECUTE ON FUNCTION inv_watch_title(bigint), inv_feed_key_calls_today(bigint) TO inventory_rw, inventory_records_ro;
REVOKE ALL ON FUNCTION inv_notify(bigint, text, text, bigint, text, text, text, boolean), inv_fire_watches(), inv_resolve_feed_key(text), inv_rate_ok(bigint, timestamptz),
    inv_feed_key_rotate(bigint, text), inv_feed_key_revoke(bigint, bigint), inv_prune_key_usage(), inv_expire_links() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inv_notify(bigint, text, text, bigint, text, text, text, boolean), inv_fire_watches(), inv_resolve_feed_key(text), inv_rate_ok(bigint, timestamptz),
    inv_feed_key_rotate(bigint, text), inv_feed_key_revoke(bigint, bigint), inv_prune_key_usage(), inv_expire_links() TO inventory_rw;

COMMIT;

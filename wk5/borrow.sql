--
-- PostgreSQL database dump
--

\restrict 5pGqnmVWo8Ftq0hP10JdgFq6D5XVZbMLjbgku5syQxthoR9ygXryCo97MBdqK0F

-- Dumped from database version 18.6
-- Dumped by pg_dump version 18.6

-- Started on 2026-11-16 20:34:15

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- TOC entry 246 (class 1255 OID 16750)
-- Name: fn_borrow_items_audit(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.fn_borrow_items_audit() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
        DECLARE
            v_actor VARCHAR(80);
        BEGIN
            v_actor := COALESCE(NULLIF(current_setting('wk5.actor', true), ''), 'Unidentified database operator');
            IF (TG_OP = 'INSERT') THEN
                INSERT INTO borrow_items_audit (borrow_id, equipment_id, quantity, old_quantity, new_quantity, action, changed_by)
                VALUES (NEW.borrow_id, NEW.equipment_id, NEW.quantity, NULL, NEW.quantity, 'INSERT', v_actor);
            ELSIF (TG_OP = 'UPDATE') THEN
                INSERT INTO borrow_items_audit (borrow_id, equipment_id, quantity, old_quantity, new_quantity, action, changed_by)
                VALUES (NEW.borrow_id, NEW.equipment_id, NEW.quantity, OLD.quantity, NEW.quantity, 'UPDATE', v_actor);
            ELSIF (TG_OP = 'DELETE') THEN
                INSERT INTO borrow_items_audit (borrow_id, equipment_id, quantity, old_quantity, new_quantity, action, changed_by)
                VALUES (OLD.borrow_id, OLD.equipment_id, OLD.quantity, OLD.quantity, NULL, 'DELETE', v_actor);
            END IF;
            RETURN NULL;
        END;
        $$;


ALTER FUNCTION public.fn_borrow_items_audit() OWNER TO postgres;

--
-- TOC entry 244 (class 1255 OID 16751)
-- Name: fn_check_equipment_availability(integer, integer); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.fn_check_equipment_availability(p_equipment_id integer, p_requested_qty integer) RETURNS boolean
    LANGUAGE plpgsql
    AS $$
        DECLARE
            v_available_qty INT;
        BEGIN
            SELECT quantity_available INTO v_available_qty FROM equipment WHERE equipment_id = p_equipment_id;
            IF NOT FOUND OR p_requested_qty IS NULL OR p_requested_qty <= 0 THEN
                RETURN FALSE;
            END IF;
            IF v_available_qty >= p_requested_qty THEN
                RETURN TRUE;
            ELSE
                RETURN FALSE;
            END IF;
        END;
        $$;


ALTER FUNCTION public.fn_check_equipment_availability(p_equipment_id integer, p_requested_qty integer) OWNER TO postgres;

--
-- TOC entry 245 (class 1255 OID 17026)
-- Name: fn_sync_equipment_stock(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.fn_sync_equipment_stock() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
        DECLARE
            v_status VARCHAR(20);
            v_old_active BOOLEAN := FALSE;
            v_new_active BOOLEAN := FALSE;
        BEGIN
            IF (TG_OP = 'INSERT') THEN
                SELECT status INTO v_status FROM borrow_transactions WHERE borrow_id = NEW.borrow_id;
                IF lower(v_status) IN ('borrowed', 'overdue') THEN
                    UPDATE equipment SET quantity_available = quantity_available - NEW.quantity WHERE equipment_id = NEW.equipment_id;
                END IF;
            ELSIF (TG_OP = 'DELETE') THEN
                SELECT status INTO v_status FROM borrow_transactions WHERE borrow_id = OLD.borrow_id;
                IF lower(v_status) IN ('borrowed', 'overdue') THEN
                    UPDATE equipment SET quantity_available = quantity_available + OLD.quantity WHERE equipment_id = OLD.equipment_id;
                END IF;
            ELSIF (TG_OP = 'UPDATE') THEN
                SELECT status INTO v_status FROM borrow_transactions WHERE borrow_id = OLD.borrow_id;
                v_old_active := lower(v_status) IN ('borrowed', 'overdue');
                SELECT status INTO v_status FROM borrow_transactions WHERE borrow_id = NEW.borrow_id;
                v_new_active := lower(v_status) IN ('borrowed', 'overdue');
                IF v_old_active THEN
                    UPDATE equipment SET quantity_available = quantity_available + OLD.quantity WHERE equipment_id = OLD.equipment_id;
                END IF;
                IF v_new_active THEN
                    UPDATE equipment SET quantity_available = quantity_available - NEW.quantity WHERE equipment_id = NEW.equipment_id;
                END IF;
            END IF;
            RETURN NULL;
        END;
        $$;


ALTER FUNCTION public.fn_sync_equipment_stock() OWNER TO postgres;

--
-- TOC entry 248 (class 1255 OID 17173)
-- Name: fn_sync_equipment_stock_on_status_change(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.fn_sync_equipment_stock_on_status_change() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
        DECLARE
            v_item RECORD;
        BEGIN
            IF lower(OLD.status) IN ('borrowed', 'overdue')
               AND lower(NEW.status) NOT IN ('borrowed', 'overdue') THEN
                FOR v_item IN
                    SELECT equipment_id, SUM(quantity)::INT AS quantity
                    FROM borrow_items
                    WHERE borrow_id = NEW.borrow_id
                    GROUP BY equipment_id
                    ORDER BY equipment_id
                LOOP
                    UPDATE equipment
                    SET quantity_available = quantity_available + v_item.quantity
                    WHERE equipment_id = v_item.equipment_id;
                END LOOP;
            ELSIF lower(OLD.status) NOT IN ('borrowed', 'overdue')
                  AND lower(NEW.status) IN ('borrowed', 'overdue') THEN
                FOR v_item IN
                    SELECT equipment_id, SUM(quantity)::INT AS quantity
                    FROM borrow_items
                    WHERE borrow_id = NEW.borrow_id
                    GROUP BY equipment_id
                    ORDER BY equipment_id
                LOOP
                    UPDATE equipment
                    SET quantity_available = quantity_available - v_item.quantity
                    WHERE equipment_id = v_item.equipment_id
                      AND quantity_available >= v_item.quantity;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Not enough available stock to reactivate this borrowing transaction';
                    END IF;
                END LOOP;
            END IF;
            RETURN NULL;
        END;
        $$;


ALTER FUNCTION public.fn_sync_equipment_stock_on_status_change() OWNER TO postgres;

--
-- TOC entry 232 (class 1255 OID 16752)
-- Name: fn_total_borrowed(integer); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.fn_total_borrowed(p_equipment_id integer) RETURNS integer
    LANGUAGE sql
    AS $$
            SELECT COALESCE(SUM(quantity), 0)::INT FROM borrow_items WHERE equipment_id = p_equipment_id;
        $$;


ALTER FUNCTION public.fn_total_borrowed(p_equipment_id integer) OWNER TO postgres;

--
-- TOC entry 247 (class 1255 OID 16753)
-- Name: fn_validate_borrow_quantity(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.fn_validate_borrow_quantity() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
        DECLARE
            v_available_qty INT;
            v_already_held  INT := 0;
            v_status VARCHAR(20);
        BEGIN
            SELECT e.quantity_available, bt.status
            INTO v_available_qty, v_status
            FROM equipment e
            JOIN borrow_transactions bt ON bt.borrow_id = NEW.borrow_id
            WHERE e.equipment_id = NEW.equipment_id;
            IF (TG_OP = 'UPDATE' AND OLD.equipment_id = NEW.equipment_id) THEN
                v_already_held := OLD.quantity;
            END IF;
            IF lower(v_status) IN ('borrowed', 'overdue')
               AND NEW.quantity > v_available_qty + v_already_held THEN
                RAISE EXCEPTION 'Requested quantity (%) exceeds available stock (%)', NEW.quantity, v_available_qty + v_already_held;
            END IF;
            RETURN NEW;
        END;
        $$;


ALTER FUNCTION public.fn_validate_borrow_quantity() OWNER TO postgres;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- TOC entry 226 (class 1259 OID 16531)
-- Name: borrow_items; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.borrow_items (
    borrow_item_id integer NOT NULL,
    borrow_id integer NOT NULL,
    equipment_id integer NOT NULL,
    quantity integer NOT NULL,
    CONSTRAINT borrow_items_quantity_check CHECK ((quantity > 0))
);


ALTER TABLE public.borrow_items OWNER TO postgres;

--
-- TOC entry 227 (class 1259 OID 16754)
-- Name: borrow_items_audit; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.borrow_items_audit (
    audit_id integer NOT NULL,
    borrow_id integer,
    equipment_id integer,
    quantity integer,
    action character varying(10),
    changed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    old_quantity integer,
    new_quantity integer,
    changed_by character varying(80)
);


ALTER TABLE public.borrow_items_audit OWNER TO postgres;

--
-- TOC entry 228 (class 1259 OID 16759)
-- Name: borrow_items_audit_audit_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.borrow_items_audit_audit_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.borrow_items_audit_audit_id_seq OWNER TO postgres;

--
-- TOC entry 5089 (class 0 OID 0)
-- Dependencies: 228
-- Name: borrow_items_audit_audit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.borrow_items_audit_audit_id_seq OWNED BY public.borrow_items_audit.audit_id;


--
-- TOC entry 225 (class 1259 OID 16530)
-- Name: borrow_items_borrow_item_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.borrow_items_borrow_item_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.borrow_items_borrow_item_id_seq OWNER TO postgres;

--
-- TOC entry 5090 (class 0 OID 0)
-- Dependencies: 225
-- Name: borrow_items_borrow_item_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.borrow_items_borrow_item_id_seq OWNED BY public.borrow_items.borrow_item_id;


--
-- TOC entry 224 (class 1259 OID 16510)
-- Name: borrow_transactions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.borrow_transactions (
    borrow_id integer NOT NULL,
    borrower_id integer NOT NULL,
    borrow_date date DEFAULT CURRENT_DATE NOT NULL,
    due_date date NOT NULL,
    status character varying(20) DEFAULT 'borrowed'::character varying NOT NULL,
    CONSTRAINT borrow_transactions_check CHECK ((due_date >= borrow_date)),
    CONSTRAINT borrow_transactions_status_allowed_check CHECK ((lower(btrim((status)::text)) = ANY (ARRAY['pending'::text, 'borrowed'::text, 'returned'::text, 'overdue'::text])))
);


ALTER TABLE public.borrow_transactions OWNER TO postgres;

--
-- TOC entry 223 (class 1259 OID 16509)
-- Name: borrow_transactions_borrow_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.borrow_transactions_borrow_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.borrow_transactions_borrow_id_seq OWNER TO postgres;

--
-- TOC entry 5091 (class 0 OID 0)
-- Dependencies: 223
-- Name: borrow_transactions_borrow_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.borrow_transactions_borrow_id_seq OWNED BY public.borrow_transactions.borrow_id;


--
-- TOC entry 220 (class 1259 OID 16481)
-- Name: borrowers; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.borrowers (
    borrower_id integer NOT NULL,
    borrower_no character varying(20) NOT NULL,
    full_name character varying(100) NOT NULL,
    borrower_type character varying(20) NOT NULL,
    email character varying(120),
    CONSTRAINT borrowers_borrower_type_allowed_check CHECK (((borrower_type)::text = ANY ((ARRAY['student'::character varying, 'faculty'::character varying, 'staff'::character varying])::text[])))
);


ALTER TABLE public.borrowers OWNER TO postgres;

--
-- TOC entry 219 (class 1259 OID 16480)
-- Name: borrowers_borrower_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.borrowers_borrower_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.borrowers_borrower_id_seq OWNER TO postgres;

--
-- TOC entry 5092 (class 0 OID 0)
-- Dependencies: 219
-- Name: borrowers_borrower_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.borrowers_borrower_id_seq OWNED BY public.borrowers.borrower_id;


--
-- TOC entry 222 (class 1259 OID 16495)
-- Name: equipment; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.equipment (
    equipment_id integer NOT NULL,
    property_no character varying(20) NOT NULL,
    equipment_name character varying(100) NOT NULL,
    quantity_available integer DEFAULT 0 NOT NULL,
    category character varying(50),
    CONSTRAINT equipment_quantity_available_check CHECK ((quantity_available >= 0))
);


ALTER TABLE public.equipment OWNER TO postgres;

--
-- TOC entry 221 (class 1259 OID 16494)
-- Name: equipment_equipment_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.equipment_equipment_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.equipment_equipment_id_seq OWNER TO postgres;

--
-- TOC entry 5093 (class 0 OID 0)
-- Dependencies: 221
-- Name: equipment_equipment_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.equipment_equipment_id_seq OWNED BY public.equipment.equipment_id;


--
-- TOC entry 229 (class 1259 OID 16760)
-- Name: vw_active_borrows; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.vw_active_borrows AS
 SELECT b.full_name,
    bt.borrow_date,
    bt.due_date,
    e.equipment_name
   FROM (((public.borrowers b
     JOIN public.borrow_transactions bt ON ((b.borrower_id = bt.borrower_id)))
     JOIN public.borrow_items bi ON ((bt.borrow_id = bi.borrow_id)))
     JOIN public.equipment e ON ((bi.equipment_id = e.equipment_id)));


ALTER VIEW public.vw_active_borrows OWNER TO postgres;

--
-- TOC entry 230 (class 1259 OID 16765)
-- Name: vw_borrowing_details; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.vw_borrowing_details AS
 SELECT b.borrower_no,
    b.full_name,
    e.equipment_name,
    bi.quantity,
    bt.borrow_date,
    bt.due_date,
    bt.status
   FROM (((public.borrowers b
     JOIN public.borrow_transactions bt ON ((b.borrower_id = bt.borrower_id)))
     JOIN public.borrow_items bi ON ((bt.borrow_id = bi.borrow_id)))
     JOIN public.equipment e ON ((bi.equipment_id = e.equipment_id)));


ALTER VIEW public.vw_borrowing_details OWNER TO postgres;

--
-- TOC entry 231 (class 1259 OID 16770)
-- Name: vw_equipment_usage; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.vw_equipment_usage AS
SELECT
    NULL::integer AS equipment_id,
    NULL::character varying(20) AS property_no,
    NULL::character varying(100) AS equipment_name,
    NULL::bigint AS total_transactions,
    NULL::bigint AS total_quantity_borrowed,
    NULL::text AS availability_status;


ALTER VIEW public.vw_equipment_usage OWNER TO postgres;

--
-- TOC entry 4900 (class 2604 OID 16534)
-- Name: borrow_items borrow_item_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.borrow_items ALTER COLUMN borrow_item_id SET DEFAULT nextval('public.borrow_items_borrow_item_id_seq'::regclass);


--
-- TOC entry 4901 (class 2604 OID 16774)
-- Name: borrow_items_audit audit_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.borrow_items_audit ALTER COLUMN audit_id SET DEFAULT nextval('public.borrow_items_audit_audit_id_seq'::regclass);


--
-- TOC entry 4897 (class 2604 OID 16513)
-- Name: borrow_transactions borrow_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.borrow_transactions ALTER COLUMN borrow_id SET DEFAULT nextval('public.borrow_transactions_borrow_id_seq'::regclass);


--
-- TOC entry 4894 (class 2604 OID 16484)
-- Name: borrowers borrower_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.borrowers ALTER COLUMN borrower_id SET DEFAULT nextval('public.borrowers_borrower_id_seq'::regclass);


--
-- TOC entry 4895 (class 2604 OID 16498)
-- Name: equipment equipment_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.equipment ALTER COLUMN equipment_id SET DEFAULT nextval('public.equipment_equipment_id_seq'::regclass);


--
-- TOC entry 4926 (class 2606 OID 16776)
-- Name: borrow_items_audit borrow_items_audit_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.borrow_items_audit
    ADD CONSTRAINT borrow_items_audit_pkey PRIMARY KEY (audit_id);


--
-- TOC entry 4920 (class 2606 OID 16543)
-- Name: borrow_items borrow_items_borrow_id_equipment_id_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.borrow_items
    ADD CONSTRAINT borrow_items_borrow_id_equipment_id_key UNIQUE (borrow_id, equipment_id);


--
-- TOC entry 4922 (class 2606 OID 16541)
-- Name: borrow_items borrow_items_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.borrow_items
    ADD CONSTRAINT borrow_items_pkey PRIMARY KEY (borrow_item_id);


--
-- TOC entry 4918 (class 2606 OID 16524)
-- Name: borrow_transactions borrow_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.borrow_transactions
    ADD CONSTRAINT borrow_transactions_pkey PRIMARY KEY (borrow_id);


--
-- TOC entry 4909 (class 2606 OID 16493)
-- Name: borrowers borrowers_borrower_no_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.borrowers
    ADD CONSTRAINT borrowers_borrower_no_key UNIQUE (borrower_no);


--
-- TOC entry 4911 (class 2606 OID 16491)
-- Name: borrowers borrowers_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.borrowers
    ADD CONSTRAINT borrowers_pkey PRIMARY KEY (borrower_id);


--
-- TOC entry 4914 (class 2606 OID 16506)
-- Name: equipment equipment_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.equipment
    ADD CONSTRAINT equipment_pkey PRIMARY KEY (equipment_id);


--
-- TOC entry 4916 (class 2606 OID 16508)
-- Name: equipment equipment_property_no_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.equipment
    ADD CONSTRAINT equipment_property_no_key UNIQUE (property_no);


--
-- TOC entry 4912 (class 1259 OID 33608)
-- Name: equipment_equipment_name_lower_key; Type: INDEX; Schema: public; Owner: postgres
--

CREATE UNIQUE INDEX equipment_equipment_name_lower_key ON public.equipment USING btree (lower((equipment_name)::text));

-- Index foreign-key and list-query columns used by the application.
-- The existing UNIQUE (borrow_id, equipment_id) constraint already indexes borrow_id.
CREATE INDEX borrow_items_equipment_id_idx ON public.borrow_items USING btree (equipment_id);
CREATE INDEX borrow_transactions_borrower_id_idx ON public.borrow_transactions USING btree (borrower_id);
CREATE INDEX borrow_transactions_status_lower_idx ON public.borrow_transactions USING btree (lower(status));
CREATE INDEX borrow_transactions_recent_idx ON public.borrow_transactions USING btree (borrow_date DESC, borrow_id DESC);

--
-- TOC entry 5083 (class 2618 OID 16773)
-- Name: vw_equipment_usage _RETURN; Type: RULE; Schema: public; Owner: postgres
--

CREATE OR REPLACE VIEW public.vw_equipment_usage AS
 SELECT e.equipment_id,
    e.property_no,
    e.equipment_name,
    count(DISTINCT bi.borrow_id) AS total_transactions,
    sum(COALESCE(bi.quantity, 0)) AS total_quantity_borrowed,
        CASE
            WHEN (e.quantity_available > 5) THEN 'Available'::text
            WHEN ((e.quantity_available >= 1) AND (e.quantity_available <= 5)) THEN 'Low Stock'::text
            ELSE 'Out of Stock'::text
        END AS availability_status
   FROM (public.equipment e
     LEFT JOIN public.borrow_items bi ON ((e.equipment_id = bi.equipment_id)))
  GROUP BY e.equipment_id, e.property_no, e.equipment_name;


--
-- TOC entry 4931 (class 2620 OID 33678)
-- Name: borrow_items trg_borrow_items_audit; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_borrow_items_audit AFTER INSERT OR DELETE OR UPDATE ON public.borrow_items FOR EACH ROW EXECUTE FUNCTION public.fn_borrow_items_audit();


--
-- TOC entry 4932 (class 2620 OID 33679)
-- Name: borrow_items trg_sync_equipment_stock; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_sync_equipment_stock AFTER INSERT OR DELETE OR UPDATE ON public.borrow_items FOR EACH ROW EXECUTE FUNCTION public.fn_sync_equipment_stock();


--
-- TOC entry 4930 (class 2620 OID 33680)
-- Name: borrow_transactions trg_sync_equipment_stock_on_status_change; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_sync_equipment_stock_on_status_change AFTER UPDATE OF status ON public.borrow_transactions FOR EACH ROW WHEN (((old.status)::text IS DISTINCT FROM (new.status)::text)) EXECUTE FUNCTION public.fn_sync_equipment_stock_on_status_change();


--
-- TOC entry 4933 (class 2620 OID 33677)
-- Name: borrow_items trg_validate_borrow_quantity; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_validate_borrow_quantity BEFORE INSERT OR UPDATE ON public.borrow_items FOR EACH ROW EXECUTE FUNCTION public.fn_validate_borrow_quantity();


--
-- TOC entry 4928 (class 2606 OID 16544)
-- Name: borrow_items borrow_items_borrow_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.borrow_items
    ADD CONSTRAINT borrow_items_borrow_id_fkey FOREIGN KEY (borrow_id) REFERENCES public.borrow_transactions(borrow_id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- TOC entry 4929 (class 2606 OID 16549)
-- Name: borrow_items borrow_items_equipment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.borrow_items
    ADD CONSTRAINT borrow_items_equipment_id_fkey FOREIGN KEY (equipment_id) REFERENCES public.equipment(equipment_id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- TOC entry 4927 (class 2606 OID 16525)
-- Name: borrow_transactions borrow_transactions_borrower_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.borrow_transactions
    ADD CONSTRAINT borrow_transactions_borrower_id_fkey FOREIGN KEY (borrower_id) REFERENCES public.borrowers(borrower_id) ON UPDATE CASCADE ON DELETE RESTRICT;


-- Completed on 2026-11-16 20:34:15

--
-- PostgreSQL database dump complete
--

\unrestrict 5pGqnmVWo8Ftq0hP10JdgFq6D5XVZbMLjbgku5syQxthoR9ygXryCo97MBdqK0F

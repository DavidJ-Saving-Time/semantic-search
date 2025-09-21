--
-- PostgreSQL database dump
--

-- Dumped from database version 17.5
-- Dumped by pg_dump version 17.5

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
-- Name: vector; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA public;


--
-- Name: EXTENSION vector; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION vector IS 'vector data type and ivfflat and hnsw access methods';


--
-- Name: period_type; Type: TYPE; Schema: public; Owner: journal_user
--

CREATE TYPE public.period_type AS ENUM (
    'month',
    'year'
);


ALTER TYPE public.period_type OWNER TO journal_user;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: docs; Type: TABLE; Schema: public; Owner: journal_user
--

CREATE TABLE public.docs (
    id bigint NOT NULL,
    source_file text NOT NULL,
    meta jsonb NOT NULL,
    summary_raw text,
    summary_clean text,
    md text NOT NULL,
    embedding public.vector(3072),
    pubname text,
    date date,
    genre text[],
    embedding_hv public.halfvec(3072) GENERATED ALWAYS AS ((embedding)::public.halfvec(3072)) STORED,
    page integer GENERATED ALWAYS AS (
CASE
    WHEN ((meta ->> 'first_page'::text) ~ '^\d+$'::text) THEN ((meta ->> 'first_page'::text))::integer
    ELSE NULL::integer
END) STORED,
    issue text GENERATED ALWAYS AS ((meta ->> 'issue'::text)) STORED
);


ALTER TABLE public.docs OWNER TO journal_user;

--
-- Name: docs_id_seq; Type: SEQUENCE; Schema: public; Owner: journal_user
--

CREATE SEQUENCE public.docs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.docs_id_seq OWNER TO journal_user;

--
-- Name: docs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: journal_user
--

ALTER SEQUENCE public.docs_id_seq OWNED BY public.docs.id;


--
-- Name: hcontext; Type: TABLE; Schema: public; Owner: journal_user
--

CREATE TABLE public.hcontext (
    id integer NOT NULL,
    fid integer NOT NULL,
    context text
);


ALTER TABLE public.hcontext OWNER TO journal_user;

--
-- Name: hcontext_id_seq; Type: SEQUENCE; Schema: public; Owner: journal_user
--

CREATE SEQUENCE public.hcontext_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hcontext_id_seq OWNER TO journal_user;

--
-- Name: hcontext_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: journal_user
--

ALTER SEQUENCE public.hcontext_id_seq OWNED BY public.hcontext.id;


--
-- Name: pages; Type: TABLE; Schema: public; Owner: journal_user
--

CREATE TABLE public.pages (
    page_id bigint NOT NULL,
    issue text NOT NULL,
    page integer NOT NULL
);


ALTER TABLE public.pages OWNER TO journal_user;

--
-- Name: pages_page_id_seq; Type: SEQUENCE; Schema: public; Owner: journal_user
--

CREATE SEQUENCE public.pages_page_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.pages_page_id_seq OWNER TO journal_user;

--
-- Name: pages_page_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: journal_user
--

ALTER SEQUENCE public.pages_page_id_seq OWNED BY public.pages.page_id;


--
-- Name: period_embeddings; Type: TABLE; Schema: public; Owner: journal_user
--

CREATE TABLE public.period_embeddings (
    id bigint NOT NULL,
    period public.period_type NOT NULL,
    period_key text NOT NULL,
    period_start date NOT NULL,
    period_end date NOT NULL,
    pubname text,
    is_combined boolean DEFAULT false NOT NULL,
    model text NOT NULL,
    dim integer NOT NULL,
    agg_method text NOT NULL,
    article_count integer NOT NULL,
    token_count bigint NOT NULL,
    embedding public.vector(3072) NOT NULL,
    embedding_hv public.halfvec(3072) GENERATED ALWAYS AS ((embedding)::public.halfvec(3072)) STORED,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_pub_or_combined CHECK ((((is_combined = true) AND (pubname IS NULL)) OR ((is_combined = false) AND (pubname IS NOT NULL))))
);


ALTER TABLE public.period_embeddings OWNER TO journal_user;

--
-- Name: period_embeddings_id_seq; Type: SEQUENCE; Schema: public; Owner: journal_user
--

CREATE SEQUENCE public.period_embeddings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.period_embeddings_id_seq OWNER TO journal_user;

--
-- Name: period_embeddings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: journal_user
--

ALTER SEQUENCE public.period_embeddings_id_seq OWNED BY public.period_embeddings.id;


--
-- Name: query_cache; Type: TABLE; Schema: public; Owner: journal_user
--

CREATE TABLE public.query_cache (
    query_key text NOT NULL,
    emb jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.query_cache OWNER TO journal_user;

--
-- Name: topic_labels; Type: TABLE; Schema: public; Owner: journal_user
--

CREATE TABLE public.topic_labels (
    topic text NOT NULL,
    emb public.vector(3072),
    emb_hv public.halfvec(3072) GENERATED ALWAYS AS ((emb)::public.halfvec(3072)) STORED
);


ALTER TABLE public.topic_labels OWNER TO journal_user;

--
-- Name: docs id; Type: DEFAULT; Schema: public; Owner: journal_user
--

ALTER TABLE ONLY public.docs ALTER COLUMN id SET DEFAULT nextval('public.docs_id_seq'::regclass);


--
-- Name: hcontext id; Type: DEFAULT; Schema: public; Owner: journal_user
--

ALTER TABLE ONLY public.hcontext ALTER COLUMN id SET DEFAULT nextval('public.hcontext_id_seq'::regclass);


--
-- Name: pages page_id; Type: DEFAULT; Schema: public; Owner: journal_user
--

ALTER TABLE ONLY public.pages ALTER COLUMN page_id SET DEFAULT nextval('public.pages_page_id_seq'::regclass);


--
-- Name: period_embeddings id; Type: DEFAULT; Schema: public; Owner: journal_user
--

ALTER TABLE ONLY public.period_embeddings ALTER COLUMN id SET DEFAULT nextval('public.period_embeddings_id_seq'::regclass);


--
-- Name: docs docs_pkey; Type: CONSTRAINT; Schema: public; Owner: journal_user
--

ALTER TABLE ONLY public.docs
    ADD CONSTRAINT docs_pkey PRIMARY KEY (id);


--
-- Name: docs docs_source_file_key; Type: CONSTRAINT; Schema: public; Owner: journal_user
--

ALTER TABLE ONLY public.docs
    ADD CONSTRAINT docs_source_file_key UNIQUE (source_file);


--
-- Name: hcontext hcontext_pkey; Type: CONSTRAINT; Schema: public; Owner: journal_user
--

ALTER TABLE ONLY public.hcontext
    ADD CONSTRAINT hcontext_pkey PRIMARY KEY (id);


--
-- Name: pages pages_issue_page_key; Type: CONSTRAINT; Schema: public; Owner: journal_user
--

ALTER TABLE ONLY public.pages
    ADD CONSTRAINT pages_issue_page_key UNIQUE (issue, page);


--
-- Name: pages pages_pkey; Type: CONSTRAINT; Schema: public; Owner: journal_user
--

ALTER TABLE ONLY public.pages
    ADD CONSTRAINT pages_pkey PRIMARY KEY (page_id);


--
-- Name: period_embeddings period_embeddings_pkey; Type: CONSTRAINT; Schema: public; Owner: journal_user
--

ALTER TABLE ONLY public.period_embeddings
    ADD CONSTRAINT period_embeddings_pkey PRIMARY KEY (id);


--
-- Name: query_cache query_cache_pkey; Type: CONSTRAINT; Schema: public; Owner: journal_user
--

ALTER TABLE ONLY public.query_cache
    ADD CONSTRAINT query_cache_pkey PRIMARY KEY (query_key);


--
-- Name: topic_labels topic_labels_pkey; Type: CONSTRAINT; Schema: public; Owner: journal_user
--

ALTER TABLE ONLY public.topic_labels
    ADD CONSTRAINT topic_labels_pkey PRIMARY KEY (topic);


--
-- Name: docs_date_page_id_idx; Type: INDEX; Schema: public; Owner: journal_user
--

CREATE INDEX docs_date_page_id_idx ON public.docs USING btree (date, page, id);


--
-- Name: docs_embedding_hnsw_hv; Type: INDEX; Schema: public; Owner: journal_user
--

CREATE INDEX docs_embedding_hnsw_hv ON public.docs USING hnsw (embedding_hv public.halfvec_cosine_ops);


--
-- Name: docs_issue_page_idx; Type: INDEX; Schema: public; Owner: journal_user
--

CREATE INDEX docs_issue_page_idx ON public.docs USING btree (issue, page);


--
-- Name: pages_issue_page_idx; Type: INDEX; Schema: public; Owner: journal_user
--

CREATE INDEX pages_issue_page_idx ON public.pages USING btree (issue, page);


--
-- Name: topic_labels_emb_hnsw_hv; Type: INDEX; Schema: public; Owner: journal_user
--

CREATE INDEX topic_labels_emb_hnsw_hv ON public.topic_labels USING hnsw (emb_hv public.halfvec_cosine_ops);


--
-- Name: ux_period_combined; Type: INDEX; Schema: public; Owner: journal_user
--

CREATE UNIQUE INDEX ux_period_combined ON public.period_embeddings USING btree (period, period_key) WHERE ((is_combined = true) AND (pubname IS NULL));


--
-- Name: ux_period_per_pub; Type: INDEX; Schema: public; Owner: journal_user
--

CREATE UNIQUE INDEX ux_period_per_pub ON public.period_embeddings USING btree (period, period_key, pubname) WHERE ((is_combined = false) AND (pubname IS NOT NULL));


--
-- Name: SCHEMA public; Type: ACL; Schema: -; Owner: pg_database_owner
--

GRANT CREATE ON SCHEMA public TO journal_user;


--
-- PostgreSQL database dump complete
--


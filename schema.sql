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
    embedding_hv public.halfvec(3072) GENERATED ALWAYS AS ((embedding)::public.halfvec(3072)) STORED
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
-- Name: docs_embedding_hnsw_hv; Type: INDEX; Schema: public; Owner: journal_user
--

CREATE INDEX docs_embedding_hnsw_hv ON public.docs USING hnsw (embedding_hv public.halfvec_cosine_ops);


--
-- Name: topic_labels_emb_hnsw_hv; Type: INDEX; Schema: public; Owner: journal_user
--

CREATE INDEX topic_labels_emb_hnsw_hv ON public.topic_labels USING hnsw (emb_hv public.halfvec_cosine_ops);


--
-- Name: SCHEMA public; Type: ACL; Schema: -; Owner: pg_database_owner
--

GRANT CREATE ON SCHEMA public TO journal_user;


--
-- PostgreSQL database dump complete
--


drop table if exists app_session;

create table app_session (
  username        text,
  full_name       text,
  role_name       text,
  status          text        not null default 'Guest (not logged in)',
  device          text,
  ip_address      text,
  logged_in_at    timestamptz,
  last_active_at  timestamptz not null default now(),
  expires_at      timestamptz not null default now() + interval '8 hours',
  user_id         int,
  first_seen_at   timestamptz not null default now(),
  id              text primary key,
  data            text        not null default ''
);

comment on table  app_session is 'Browser sessions of Sproutly. Readable columns first; id and data are technical.';
comment on column app_session.status         is 'Logged in, or Guest (not logged in). Rows past expires_at are expired and get cleaned up.';
comment on column app_session.device         is 'Browser and operating system, e.g. Chrome on Windows';
comment on column app_session.last_active_at is 'Last time this browser opened a Sproutly page';
comment on column app_session.expires_at     is 'Session ends at this time if the person does nothing';
comment on column app_session.id             is 'TECHNICAL + SECRET: the session key kept in the browser cookie. Never share it.';
comment on column app_session.data           is 'TECHNICAL: PHP session memory (e.g. csrf token). Not meant to be read or edited.';

create index app_session_expires_idx on app_session (expires_at);

alter table app_session enable row level security;
revoke all on app_session from anon, authenticated;
grant all privileges on app_session to service_role;
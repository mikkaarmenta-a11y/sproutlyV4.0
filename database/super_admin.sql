insert into user_role (role_id, role_name) overriding system value
values (3, 'Super Admin')
on conflict (role_id) do update set role_name = excluded.role_name;

select setval(pg_get_serial_sequence('user_role', 'role_id'), (select max(role_id) from user_role));

-- Users
update app_user set role_id = 3 where lower(full_name) = 'mikka armenta' and deleted_at is null;
update app_user set role_id = 2 where lower(full_name) = 'ariana grande' and deleted_at is null;

create extension if not exists pgcrypto with schema extensions;

insert into app_user (username, full_name, email, role_id, password_hash, created_by)
select 'matty', 'Matty Healy', 'matty.healy@example.com', 1,
       extensions.crypt('ChangeMe123', extensions.gen_salt('bf', 10)), 'migration'
where not exists (select 1 from app_user where lower(full_name) = 'matty healy' and deleted_at is null)
  and not exists (select 1 from app_user where username = 'matty' and deleted_at is null);

update app_user set role_id = 1 where lower(full_name) = 'matty healy' and deleted_at is null;

-- Activity log (readable columns, newest first in the app)
create table if not exists audit_log (
  log_id             bigint generated always as identity primary key,
  logged_at          timestamptz not null default now(),
  action             text not null check (action in ('delete', 'update', 'restore', 'revert')),
  table_name         text not null,
  record_id          int  not null,
  record_label       text,
  performed_by       text,
  old_data           jsonb,
  new_data           jsonb,
  changed_fields     text[],
  deleted_stamp      timestamptz,
  cascade_from_table text,
  cascade_from_id    int,
  undone_at          timestamptz,
  undone_by          text
);
create index if not exists audit_log_logged_idx on audit_log (logged_at desc);
create index if not exists audit_log_action_idx on audit_log (action, undone_at);
create index if not exists audit_log_stamp_idx  on audit_log (deleted_stamp);

comment on table  audit_log is 'Every delete/update/undelete/undo made in the app. Written by database triggers.';
comment on column audit_log.action             is 'delete = soft-deleted, update = fields changed, restore = undeleted, revert = update undone';
comment on column audit_log.record_label       is 'Human readable name of the record, e.g. "Farmer: Dela Cruz, Juan"';
comment on column audit_log.old_data           is 'delete: the whole record. update/revert: only the fields that changed (before).';
comment on column audit_log.new_data           is 'update/revert: only the fields that changed (after).';
comment on column audit_log.cascade_from_table is 'If this record was deleted because a connected record was deleted, which table that was.';
comment on column audit_log.undone_at          is 'Set when a delete was undeleted or an update was undone.';

alter table audit_log enable row level security;
revoke all on audit_log from anon, authenticated;
grant all privileges on audit_log to service_role;

-- Helper functions
create or replace function audit_strip(j jsonb) returns jsonb language sql immutable as $$
  select j - 'password_hash'
$$;

create or replace function audit_label(t text, r jsonb) returns text language plpgsql as $$
begin
  return case t
    when 'farmer'   then 'Farmer: ' || (r->>'last_name') || ', ' || (r->>'first_name')
    when 'farm'     then 'Farm: ' || (r->>'farm_name')
    when 'crop'     then 'Crop: ' || (r->>'crop_name') || ' (' || (r->>'variety') || ')'
    when 'planting' then 'Planting #' || (r->>'planting_id') || ' - '
                         || coalesce((select crop_name from crop where crop_id = (r->>'crop_id')::int), '?') || ' @ '
                         || coalesce((select farm_name from farm where farm_id = (r->>'farm_id')::int), '?')
    when 'harvest'  then 'Harvest #' || (r->>'harvest_id') || ' (planting #' || (r->>'planting_id') || ')'
    when 'app_user' then 'User: ' || (r->>'full_name') || ' (' || (r->>'username') || ')'
    else t
  end;
end $$;

-- Blocks an undelete when a parent record is still deleted (it would be an orphan).
create or replace function trg_check_restore() returns trigger language plpgsql as $$
begin
  if old.deleted_at is not null and new.deleted_at is null then
    if tg_table_name = 'farm' then
      if not exists (select 1 from farmer where farmer_id = new.farmer_id and deleted_at is null) then
        raise exception 'Cannot undelete this farm: its owner (farmer) is still deleted. Undelete the farmer first.';
      end if;
    elsif tg_table_name = 'planting' then
      if not exists (select 1 from farm where farm_id = new.farm_id and deleted_at is null) then
        raise exception 'Cannot undelete this planting record: its farm is still deleted. Undelete the farm first.';
      end if;
      if not exists (select 1 from crop where crop_id = new.crop_id and deleted_at is null) then
        raise exception 'Cannot undelete this planting record: its crop is still deleted. Undelete the crop first.';
      end if;
    elsif tg_table_name = 'harvest' then
      if not exists (select 1 from planting where planting_id = new.planting_id and deleted_at is null) then
        raise exception 'Cannot undelete this harvest record: its planting record is still deleted. Undelete the planting record first.';
      end if;
    end if;
  end if;
  return new;
end $$;

-- Logs every update/delete/undelete and carries deletes/undeletes down to connected records.
create or replace function trg_audit_change() returns trigger language plpgsql as $$
declare
  o jsonb := to_jsonb(old);
  n jsonb := to_jsonb(new);
  rid int := (to_jsonb(new) ->> tg_argv[0])::int;
  k text;
  od jsonb := '{}'::jsonb;
  nd jsonb := '{}'::jsonb;
  changed text[] := '{}';
  root_t text;
  root_id int;
  is_root boolean;
begin
  -- ===== DELETE (soft) =====
  if old.deleted_at is null and new.deleted_at is not null then
    root_t  := nullif(current_setting('app.cascade_table', true), '');
    root_id := nullif(current_setting('app.cascade_id', true), '')::int;
    is_root := root_t is null;

    insert into audit_log (action, table_name, record_id, record_label, performed_by, old_data,
                           deleted_stamp, cascade_from_table, cascade_from_id)
    values ('delete', tg_table_name, rid, audit_label(tg_table_name, o), new.updated_by, audit_strip(o),
            new.deleted_at, root_t, root_id);

    if is_root then
      perform set_config('app.cascade_table', tg_table_name, true);
      perform set_config('app.cascade_id', rid::text, true);
    end if;

    if tg_table_name = 'farmer' then
      update farm set deleted_at = new.deleted_at, updated_by = new.updated_by
        where farmer_id = rid and deleted_at is null;
    elsif tg_table_name = 'farm' then
      update planting set deleted_at = new.deleted_at, updated_by = new.updated_by
        where farm_id = rid and deleted_at is null;
    elsif tg_table_name = 'crop' then
      update planting set deleted_at = new.deleted_at, updated_by = new.updated_by
        where crop_id = rid and deleted_at is null;
    elsif tg_table_name = 'planting' then
      update harvest set deleted_at = new.deleted_at, updated_by = new.updated_by
        where planting_id = rid and deleted_at is null;
    end if;

    if is_root then
      perform set_config('app.cascade_table', '', true);
      perform set_config('app.cascade_id', '', true);
    end if;
    return null;
  end if;

  -- ===== UNDELETE =====
  if old.deleted_at is not null and new.deleted_at is null then
    insert into audit_log (action, table_name, record_id, record_label, performed_by, deleted_stamp)
    values ('restore', tg_table_name, rid, audit_label(tg_table_name, o), new.updated_by, old.deleted_at);

    update audit_log set undone_at = now(), undone_by = new.updated_by
      where table_name = tg_table_name and record_id = rid and action = 'delete'
        and undone_at is null and deleted_stamp = old.deleted_at;

    -- Bring back only what was deleted together with this record (same moment),
    -- and only if every other parent of that record is active.
    if tg_table_name = 'farmer' then
      update farm set deleted_at = null, updated_by = new.updated_by
        where farmer_id = rid and deleted_at = old.deleted_at;
    elsif tg_table_name = 'farm' then
      update planting set deleted_at = null, updated_by = new.updated_by
        where farm_id = rid and deleted_at = old.deleted_at
          and exists (select 1 from crop c where c.crop_id = planting.crop_id and c.deleted_at is null);
    elsif tg_table_name = 'crop' then
      update planting set deleted_at = null, updated_by = new.updated_by
        where crop_id = rid and deleted_at = old.deleted_at
          and exists (select 1 from farm f where f.farm_id = planting.farm_id and f.deleted_at is null);
    elsif tg_table_name = 'planting' then
      update harvest set deleted_at = null, updated_by = new.updated_by
        where planting_id = rid and deleted_at = old.deleted_at;
    end if;
    return null;
  end if;

  -- ===== UPDATE (fields changed) =====
  for k in select jsonb_object_keys(n) loop
    if k in ('updated_at', 'updated_by', 'created_at', 'created_by', 'deleted_at') then continue; end if;
    if o -> k is distinct from n -> k then
      changed := changed || k;
      if k = 'password_hash' then
        od := od || jsonb_build_object(k, '(hidden)');
        nd := nd || jsonb_build_object(k, '(hidden)');
      else
        od := od || jsonb_build_object(k, o -> k);
        nd := nd || jsonb_build_object(k, n -> k);
      end if;
    end if;
  end loop;

  if array_length(changed, 1) is not null then
    insert into audit_log (action, table_name, record_id, record_label, performed_by, old_data, new_data, changed_fields)
    values (case when coalesce(current_setting('app.action', true), '') = 'revert' then 'revert' else 'update' end,
            tg_table_name, rid, audit_label(tg_table_name, n), new.updated_by, od, nd, changed);
  end if;
  return null;
end $$;

-- Undo one logged update (only if nothing changed the same fields since).
create or replace function revert_update(p_log_id bigint, p_by text) returns jsonb language plpgsql as $$
declare
  l audit_log%rowtype;
  pk text;
  cur jsonb;
  k text;
  od jsonb;
  sets text;
begin
  select * into l from audit_log where log_id = p_log_id for update;
  if not found then raise exception 'That activity entry no longer exists.'; end if;
  if l.action <> 'update' then raise exception 'Only updates can be undone.'; end if;
  if l.undone_at is not null then raise exception 'This update was already undone.'; end if;

  pk := case l.table_name
          when 'farmer' then 'farmer_id' when 'farm' then 'farm_id' when 'crop' then 'crop_id'
          when 'planting' then 'planting_id' when 'harvest' then 'harvest_id' when 'app_user' then 'user_id' end;
  if pk is null then raise exception 'Unknown record type.'; end if;

  execute format('select to_jsonb(t) from public.%I t where %I = $1 and deleted_at is null', l.table_name, pk)
    into cur using l.record_id;
  if cur is null then
    raise exception 'This record is deleted. Undelete it first, then undo the update.';
  end if;

  od := l.old_data - 'password_hash';
  if od = '{}'::jsonb then
    raise exception 'Nothing to undo (a password change cannot be undone).';
  end if;

  for k in select jsonb_object_keys(od) loop
    if cur -> k is distinct from l.new_data -> k then
      raise exception 'This record was changed again after this update. Undo the newer update first.';
    end if;
  end loop;

  select string_agg(format('%1$I = (select r.%1$I from jsonb_populate_record(null::public.%2$I, $1) r)', x.key, l.table_name), ', ')
    into sets from jsonb_object_keys(od) as x(key);

  perform set_config('app.action', 'revert', true);
  execute format('update public.%I set %s, updated_by = $2 where %I = $3', l.table_name, sets, pk)
    using od, p_by, l.record_id;
  perform set_config('app.action', '', true);

  update audit_log set undone_at = now(), undone_by = p_by where log_id = p_log_id;
  return jsonb_build_object('ok', true, 'table', l.table_name, 'label', l.record_label);
end $$;

revoke execute on function revert_update(bigint, text) from public, anon, authenticated;
grant  execute on function revert_update(bigint, text) to service_role;

-- Triggers
do $$
declare r record;
begin
  for r in select * from (values ('farmer','farmer_id'), ('farm','farm_id'), ('crop','crop_id'),
                                 ('planting','planting_id'), ('harvest','harvest_id'), ('app_user','user_id')) v(t, pk) loop
    execute format('drop trigger if exists trg_%1$s_audit on %1$I', r.t);
    execute format('create trigger trg_%1$s_audit after update on %1$I
                    for each row execute function trg_audit_change(%2$L)', r.t, r.pk);
  end loop;
end $$;

do $$
declare t text;
begin
  foreach t in array array['farm','planting','harvest'] loop
    execute format('drop trigger if exists trg_%1$s_restorecheck on %1$I', t);
    execute format('create trigger trg_%1$s_restorecheck before update on %1$I
                    for each row execute function trg_check_restore()', t);
  end loop;
end $$;

notify pgrst, 'reload schema';

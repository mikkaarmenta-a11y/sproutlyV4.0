do $$
declare t text;
begin
  foreach t in array array['farmer','farm','crop','planting','harvest'] loop
    execute format('alter table %I add column if not exists approval_status text not null default ''approved''', t);
    execute format('alter table %I add column if not exists approved_by text', t);
    execute format('alter table %I add column if not exists approved_at timestamptz', t);
    execute format('alter table %I drop constraint if exists %I', t, t || '_approval_status_check');
    execute format('alter table %I add constraint %I check (approval_status in (''pending'', ''approved'', ''rejected''))',
                   t, t || '_approval_status_check');
    execute format('create index if not exists %I on %I (approval_status)', t || '_approval_idx', t);
  end loop;
end $$;

do $$
declare d text;
begin
  select pg_get_functiondef('trg_audit_change()'::regprocedure) into d;
  if d not like '%approval_status%' then
    d := replace(d, '''deleted_at'') then continue',
                    '''deleted_at'', ''approval_status'', ''approved_by'', ''approved_at'') then continue');
    execute d;
  end if;
end $$;

notify pgrst, 'reload schema';
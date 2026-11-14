alter table app_user add column if not exists approval_status text not null default 'approved';
alter table app_user add column if not exists approved_by text;
alter table app_user add column if not exists approved_at timestamptz;

alter table app_user drop constraint if exists app_user_approval_status_check;
alter table app_user add constraint app_user_approval_status_check
  check (approval_status in ('pending', 'approved', 'rejected'));

comment on column app_user.approval_status is 'pending = waiting for Super Admin, approved = can sign in, rejected = declined';

notify pgrst, 'reload schema';